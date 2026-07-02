<?php

namespace App\Filament\Pages;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPlatformSetting;
use App\Services\WhatsAppService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemStatusPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';
    protected static ?string $navigationLabel = 'Estado del Sistema';
    protected static ?string $title = 'Estado del Sistema';
    protected static ?string $slug = 'system-status';

    protected string $view = 'filament.pages.system-status-page';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->is_super_admin;
    }

    /**
     * Computed fresh on every render (and on each wire:poll tick) so the
     * page always reflects current state without a manual refresh.
     */
    public function getStatusData(): array
    {
        $settings = WhatsAppPlatformSetting::current();

        return [
            'meta' => $this->checkMetaConnection($settings),
            'ai' => $this->checkAiConfigured($settings),
            'queue' => $this->checkQueueHealth(),
            'deliveries' => $this->checkRecentDeliveries(),
            'recentFailures' => $this->recentFailures(),
        ];
    }

    protected function checkMetaConnection(WhatsAppPlatformSetting $settings): array
    {
        if (empty($settings->wa_phone_number_id) || empty($settings->wa_access_token)) {
            return ['ok' => false, 'message' => 'Credenciales de WhatsApp no configuradas.'];
        }

        // Cacheado brevemente: esta página se auto-refresca cada 30s vía
        // wire:poll y no queremos golpear la API de Meta en cada tick.
        return Cache::remember('system_status_meta_check', 60, function () use ($settings) {
            $result = WhatsAppService::testConnection($settings->wa_phone_number_id, $settings->wa_access_token);

            return ['ok' => $result['success'], 'message' => $result['message']];
        });
    }

    protected function checkAiConfigured(WhatsAppPlatformSetting $settings): array
    {
        $ok = !empty($settings->ai_provider) && !empty($settings->ai_model) && !empty($settings->ai_api_key);

        return [
            'ok' => $ok,
            'message' => $ok
                ? "{$settings->ai_provider} / {$settings->ai_model}"
                : 'Proveedor, modelo o llave de IA sin configurar.',
        ];
    }

    protected function checkQueueHealth(): array
    {
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        $oldestPendingCreatedAt = DB::table('jobs')->min('created_at');
        $oldestPendingAgeMinutes = $oldestPendingCreatedAt
            ? Carbon::createFromTimestamp($oldestPendingCreatedAt)->diffInMinutes(now())
            : null;

        // Si el job pendiente más antiguo lleva más de 5 minutos esperando,
        // lo más probable es que el worker no esté corriendo.
        $stalled = $oldestPendingAgeMinutes !== null && $oldestPendingAgeMinutes > 5;

        return [
            'pending' => $pending,
            'failed' => $failed,
            'oldestPendingAgeMinutes' => $oldestPendingAgeMinutes,
            'stalled' => $stalled,
        ];
    }

    /**
     * delivery_status guarda el ÚLTIMO estado que reportó Meta para cada
     * mensaje (sent -> delivered -> read se van SOBRESCRIBIENDO). Por eso
     * esto NO es un embudo acumulado ("de los enviados, cuántos llegaron a
     * entregados") sino una foto del estado FINAL de cada mensaje: un
     * mensaje que llegó a "leído" ya no cuenta como "entregado" ni
     * "enviado", cuenta solo en "leído".
     */
    protected function checkRecentDeliveries(): array
    {
        $since = now()->subDay();

        $outboundRoles = ['assistant', 'system'];

        $withWamid = WhatsAppMessage::where('created_at', '>=', $since)->whereNotNull('wamid');

        $total = (clone $withWamid)->count();

        $counts = (clone $withWamid)
            ->selectRaw('delivery_status, count(*) as total')
            ->groupBy('delivery_status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->delivery_status ?? 'awaiting' => $row->total]);

        // Mensajes que ni siquiera lograron generar un wamid: la llamada a la
        // API de Meta falló por completo (token inválido, timeout, etc.) —
        // hoy invisibles en los contadores de arriba porque nunca llegan a
        // tener delivery_status.
        $apiSendFailures = WhatsAppMessage::where('created_at', '>=', $since)
            ->whereIn('role', $outboundRoles)
            ->whereNull('wamid')
            ->count();

        return [
            'total' => $total,
            'read' => (int) ($counts['read'] ?? 0),
            'deliveredOnly' => (int) ($counts['delivered'] ?? 0),
            'sentOnly' => (int) ($counts['sent'] ?? 0),
            'awaitingStatus' => (int) ($counts['awaiting'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'apiSendFailures' => $apiSendFailures,
        ];
    }

    protected function recentFailures()
    {
        $metaRejected = WhatsAppMessage::where('delivery_status', 'failed')
            ->where('created_at', '>=', now()->subDays(2))
            ->get(['id', 'store_id', 'customer_phone', 'content', 'delivery_error', 'created_at'])
            ->map(fn ($m) => [
                'tipo' => 'Rechazado por Meta',
                'store_id' => $m->store_id,
                'customer_phone' => $m->customer_phone,
                'content' => $m->content,
                'reason' => $this->formatMetaError($m->delivery_error),
                'created_at' => $m->created_at,
            ]);

        $apiFailed = WhatsAppMessage::whereIn('role', ['assistant', 'system'])
            ->whereNull('wamid')
            ->where('created_at', '>=', now()->subDays(2))
            ->get(['id', 'store_id', 'customer_phone', 'content', 'created_at'])
            ->map(fn ($m) => [
                'tipo' => 'Fallo al enviar (API)',
                'store_id' => $m->store_id,
                'customer_phone' => $m->customer_phone,
                'content' => $m->content,
                'reason' => 'La llamada a la API de Meta no llegó a completarse — revisar logs.',
                'created_at' => $m->created_at,
            ]);

        $storeNames = \App\Models\Store::pluck('name', 'id');

        return $metaRejected->concat($apiFailed)
            ->sortByDesc('created_at')
            ->take(10)
            ->map(fn ($row) => array_merge($row, [
                'store_name' => $storeNames[$row['store_id']] ?? "#{$row['store_id']}",
            ]));
    }

    protected function formatMetaError(?string $deliveryError): string
    {
        $decoded = json_decode($deliveryError ?? '[]', true);

        return $decoded[0]['title'] ?? $decoded[0]['message'] ?? ($deliveryError ?? 'Motivo desconocido');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Actualizar')
                ->icon('heroicon-m-arrow-path')
                ->action(fn () => Notification::make()->title('Actualizado')->success()->send()),
        ];
    }
}
