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

    protected function checkRecentDeliveries(): array
    {
        $since = now()->subDay();

        $counts = WhatsAppMessage::where('created_at', '>=', $since)
            ->whereNotNull('delivery_status')
            ->selectRaw('delivery_status, count(*) as total')
            ->groupBy('delivery_status')
            ->pluck('total', 'delivery_status');

        return [
            'sent' => (int) ($counts['sent'] ?? 0),
            'delivered' => (int) ($counts['delivered'] ?? 0),
            'read' => (int) ($counts['read'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
        ];
    }

    protected function recentFailures()
    {
        return WhatsAppMessage::with('store:id,name')
            ->where('delivery_status', 'failed')
            ->where('created_at', '>=', now()->subDays(2))
            ->latest()
            ->limit(10)
            ->get(['id', 'store_id', 'customer_phone', 'content', 'delivery_error', 'created_at']);
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
