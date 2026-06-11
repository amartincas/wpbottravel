<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
     *
     * Retorna array con [from, to] como Carbon, o null si no se reconoce.
     */
    public static function parseCommand(string $text): ?array
    {
        $normalized = strtolower(trim($text));

        // Debe empezar con "reporte"
        if (!str_starts_with($normalized, 'reporte')) {
            return null;
        }

        $rest = trim(substr($normalized, 7)); // quitar "reporte"

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
     * Genera el reporte para el restaurante.
     * Solo muestra pedidos del store sin información de costos/márgenes.
     */
    public static function restaurantReport(int $storeId, Carbon $from, Carbon $to, string $label): string
    {
        $leads = Lead::where('store_id', $storeId)
            ->whereBetween('created_at', [
                $from->copy()->setTimezone('UTC'),
                $to->copy()->setTimezone('UTC'),
            ])
            ->get();

        $total    = $leads->count();
        $ventas   = $leads->sum(fn ($l) => (float) preg_replace('/[^0-9.]/', '', $l->total_amount ?? '0'));
        $entregados = $leads->where('status', Lead::STATUS_ENTREGADO)->count();
        $cancelados = $leads->where('status', Lead::STATUS_CANCELADO)->count();
        $pendientes = $leads->whereNotIn('status', [Lead::STATUS_ENTREGADO, Lead::STATUS_CANCELADO])->count();

        if ($total === 0) {
            return "📊 Reporte: {$label}\n\nNo se encontraron pedidos en este período.";
        }

        $lines = [
            "📊 *Reporte: {$label}*",
            "",
            "📦 Pedidos totales: {$total}",
            "✅ Entregados: {$entregados}",
            "⏳ En proceso: {$pendientes}",
            "❌ Cancelados: {$cancelados}",
            "💰 Ventas: $" . number_format($ventas, 0, ',', '.'),
        ];

        // Top productos
        $topProducts = $leads
            ->groupBy('product_service_name')
            ->map(fn ($g) => $g->count())
            ->sortDesc()
            ->take(3);

        if ($topProducts->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "🍗 *Productos más pedidos:*";
            foreach ($topProducts as $product => $count) {
                $name = $product ?: 'Sin especificar';
                $lines[] = "  • {$name}: {$count}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Genera el reporte completo para el superadmin.
     * Incluye métricas adicionales y resumen por estado.
     */
    public static function superAdminReport(int $storeId, Carbon $from, Carbon $to, string $label): string
    {
        $leads = Lead::where('store_id', $storeId)
            ->whereBetween('created_at', [
                $from->copy()->setTimezone('UTC'),
                $to->copy()->setTimezone('UTC'),
            ])
            ->get();

        $total      = $leads->count();
        $ventas     = $leads->sum(fn ($l) => (float) preg_replace('/[^0-9.]/', '', $l->total_amount ?? '0'));
        $entregados = $leads->where('status', Lead::STATUS_ENTREGADO)->count();
        $cancelados = $leads->where('status', Lead::STATUS_CANCELADO)->count();
        $pendientes = $leads->whereNotIn('status', [Lead::STATUS_ENTREGADO, Lead::STATUS_CANCELADO])->count();
        $ticketProm = $total > 0 ? $ventas / $total : 0;
        $ventasEntregadas = $leads
            ->where('status', Lead::STATUS_ENTREGADO)
            ->sum(fn ($l) => (float) preg_replace('/[^0-9.]/', '', $l->total_amount ?? '0'));

        if ($total === 0) {
            return "📊 Reporte Admin: {$label}\n\nNo se encontraron pedidos en este período.";
        }

        $lines = [
            "📊 *Reporte Admin: {$label}*",
            "",
            "📦 Pedidos totales: {$total}",
            "✅ Entregados: {$entregados}",
            "⏳ En proceso: {$pendientes}",
            "❌ Cancelados: {$cancelados}",
            "",
            "💰 Ventas totales: $" . number_format($ventas, 0, ',', '.'),
            "💵 Ventas entregadas: $" . number_format($ventasEntregadas, 0, ',', '.'),
            "🎯 Ticket promedio: $" . number_format($ticketProm, 0, ',', '.'),
        ];

        // Top productos
        $topProducts = $leads
            ->groupBy('product_service_name')
            ->map(fn ($g) => $g->count())
            ->sortDesc()
            ->take(3);

        if ($topProducts->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "🍗 *Productos más pedidos:*";
            foreach ($topProducts as $product => $count) {
                $name = $product ?: 'Sin especificar';
                $lines[] = "  • {$name}: {$count}";
            }
        }

        // Clientes únicos
        $clientesUnicos = $leads->pluck('customer_phone')->unique()->count();
        $lines[] = "";
        $lines[] = "👥 Clientes únicos: {$clientesUnicos}";

        return implode("\n", $lines);
    }
}
