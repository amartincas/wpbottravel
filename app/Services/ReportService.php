<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Store;
use Carbon\Carbon;

class ReportService
{
    /**
     * Parsea el comando de reporte y retorna el rango de fechas.
     *
     * Formatos soportados:
     *   Reporte Hoy
     *   Reporte Mes
     *   Reporte 01-06-2026
     *   Reporte 01-06-2026 al 30-06-2026
     */
    public static function parseCommand(string $text): ?array
    {
        $normalized = strtolower(trim($text));

        if (!str_starts_with($normalized, 'reporte')) {
            return null;
        }

        $rest = trim(substr($normalized, 7));

        // Reporte Hoy
        if ($rest === 'hoy' || $rest === '') {
            $today = Carbon::today('America/Bogota');
            return ['from' => $today->copy()->startOfDay(), 'to' => $today->copy()->endOfDay(), 'label' => 'Hoy'];
        }

        // Reporte Mes
        if ($rest === 'mes') {
            $now = Carbon::now('America/Bogota');
            return [
                'from'  => $now->copy()->startOfMonth(),
                'to'    => $now->copy()->endOfDay(),
                'label' => 'Este mes (' . $now->format('M Y') . ')',
            ];
        }

        // Reporte 01-06-2026 al 30-06-2026
        if (preg_match('/(\d{2}-\d{2}-\d{4})\s+al\s+(\d{2}-\d{2}-\d{4})/', $rest, $m)) {
            try {
                $from = Carbon::createFromFormat('d-m-Y', $m[1], 'America/Bogota')->startOfDay();
                $to   = Carbon::createFromFormat('d-m-Y', $m[2], 'America/Bogota')->endOfDay();
                return [
                    'from'  => $from,
                    'to'    => $to,
                    'label' => $from->format('d/m/Y') . ' al ' . $to->format('d/m/Y'),
                ];
            } catch (\Exception $e) {
                return null;
            }
        }

        // Reporte 01-06-2026 (día específico)
        if (preg_match('/(\d{2}-\d{2}-\d{4})/', $rest, $m)) {
            try {
                $day = Carbon::createFromFormat('d-m-Y', $m[1], 'America/Bogota');
                return [
                    'from'  => $day->copy()->startOfDay(),
                    'to'    => $day->copy()->endOfDay(),
                    'label' => $day->format('d/m/Y'),
                ];
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Reporte para el operador/asesor.
     * Solo muestra sus propias reservas.
     */
    public static function operatorReport(int $storeId, Carbon $from, Carbon $to, string $label): string
    {
        $leads = Lead::where('store_id', $storeId)
            ->whereBetween('created_at', [
                $from->copy()->setTimezone('UTC'),
                $to->copy()->setTimezone('UTC'),
            ])
            ->get();

        $total      = $leads->count();
        $ventas     = $leads->sum(fn ($l) => (float) preg_replace('/[^0-9.]/', '', $l->total_amount ?? '0'));
        $cerrados   = $leads->where('status', Lead::STATUS_CERRADO)->count();
        $cancelados = $leads->where('status', Lead::STATUS_CANCELADO)->count();
        $derivados  = $leads->whereNotIn('status', [Lead::STATUS_CERRADO, Lead::STATUS_CANCELADO])->count();

        if ($total === 0) {
            return "📊 Reporte: {$label}\n\nNo se encontraron reservas en este período.";
        }

        $lines = [
            "📊 *Reporte: {$label}*",
            "",
            "📋 Reservas totales: {$total}",
            "✅ Cerrados: {$cerrados}",
            "🧑‍💼 Derivados a asesor: {$derivados}",
            "❌ Cancelados: {$cancelados}",
            "💰 Ventas: $" . number_format($ventas, 0, ',', '.'),
        ];

        $topProducts = $leads
            ->groupBy('product_service_name')
            ->map(fn ($g) => $g->count())
            ->sortDesc()
            ->take(3);

        if ($topProducts->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "🧭 *Tours más solicitados:*";
            foreach ($topProducts as $product => $count) {
                $lines[] = "  • " . ($product ?: 'Sin especificar') . ": {$count}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Reporte consolidado para el superadmin.
     * Incluye TODOS los stores del sistema con métricas por store
     * y un resumen global al final.
     */
    public static function superAdminReport(Carbon $from, Carbon $to, string $label): string
    {
        $stores = Store::all();

        if ($stores->isEmpty()) {
            return "📊 Reporte Admin: {$label}\n\nNo hay stores configurados.";
        }

        $globalTotal      = 0;
        $globalVentas     = 0;
        $globalEntregados = 0;
        $globalCancelados = 0;
        $globalClientes   = collect();

        $lines = ["📊 *Reporte Admin: {$label}*", ""];

        foreach ($stores as $store) {
            $leads = Lead::where('store_id', $store->id)
                ->whereBetween('created_at', [
                    $from->copy()->setTimezone('UTC'),
                    $to->copy()->setTimezone('UTC'),
                ])
                ->get();

            if ($leads->isEmpty()) {
                continue;
            }

            $total      = $leads->count();
            $ventas     = $leads->sum(fn ($l) => (float) preg_replace('/[^0-9.]/', '', $l->total_amount ?? '0'));
            $cerrados   = $leads->where('status', Lead::STATUS_CERRADO)->count();
            $cancelados = $leads->where('status', Lead::STATUS_CANCELADO)->count();
            $derivados  = $leads->whereNotIn('status', [Lead::STATUS_CERRADO, Lead::STATUS_CANCELADO])->count();

            // Acumular globales
            $globalTotal      += $total;
            $globalVentas     += $ventas;
            $globalEntregados += $cerrados;
            $globalCancelados += $cancelados;
            $globalClientes   = $globalClientes->merge($leads->pluck('customer_phone'));

            $lines[] = "🏪 *{$store->name}*";
            $lines[] = "  📋 Reservas: {$total} | 💰 $" . number_format($ventas, 0, ',', '.');
            $lines[] = "  ✅ Cerrados: {$cerrados} | 🧑‍💼 Derivados: {$derivados} | ❌ Cancelados: {$cancelados}";
            $lines[] = "";
        }

        if ($globalTotal === 0) {
            return "📊 Reporte Admin: {$label}\n\nNo se encontraron reservas en este período.";
        }

        $ticketProm     = $globalTotal > 0 ? $globalVentas / $globalTotal : 0;
        $clientesUnicos = $globalClientes->unique()->count();

        $lines[] = "━━━━━━━━━━━━━━━";
        $lines[] = "📋 Total reservas: {$globalTotal}";
        $lines[] = "💰 Ventas totales: $" . number_format($globalVentas, 0, ',', '.');
        $lines[] = "✅ Cerrados: {$globalEntregados} | ❌ Cancelados: {$globalCancelados}";
        $lines[] = "🎯 Ticket promedio: $" . number_format($ticketProm, 0, ',', '.');
        $lines[] = "👥 Clientes únicos: {$clientesUnicos}";

        return implode("\n", $lines);
    }
}
