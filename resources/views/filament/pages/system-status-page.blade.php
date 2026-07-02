<x-filament-panels::page>
    <div wire:poll.30s class="flex flex-col gap-6">

        @php($status = $this->getStatusData())

        {{-- Salud general --}}
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-xl border p-4 {{ $status['meta']['ok'] ? 'border-green-300 bg-green-50 dark:bg-green-950/30' : 'border-red-300 bg-red-50 dark:bg-red-950/30' }}">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Conexión WhatsApp (Meta)</p>
                <p class="mt-1 text-lg font-semibold {{ $status['meta']['ok'] ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                    {{ $status['meta']['ok'] ? '✅ Conectado' : '❌ Problema' }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $status['meta']['message'] }}</p>
            </div>

            <div class="rounded-xl border p-4 {{ $status['ai']['ok'] ? 'border-green-300 bg-green-50 dark:bg-green-950/30' : 'border-red-300 bg-red-50 dark:bg-red-950/30' }}">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Configuración de IA</p>
                <p class="mt-1 text-lg font-semibold {{ $status['ai']['ok'] ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                    {{ $status['ai']['ok'] ? '✅ Configurada' : '❌ Incompleta' }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $status['ai']['message'] }}</p>
            </div>

            <div class="rounded-xl border p-4 {{ $status['queue']['stalled'] ? 'border-red-300 bg-red-50 dark:bg-red-950/30' : 'border-green-300 bg-green-50 dark:bg-green-950/30' }}">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Worker de la cola</p>
                <p class="mt-1 text-lg font-semibold {{ $status['queue']['stalled'] ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                    {{ $status['queue']['stalled'] ? '⚠️ Posiblemente caído' : '✅ Corriendo' }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $status['queue']['pending'] }} pendientes · {{ $status['queue']['failed'] }} fallidos
                    @if($status['queue']['oldestPendingAgeMinutes'] !== null)
                        · el más antiguo lleva {{ $status['queue']['oldestPendingAgeMinutes'] }} min esperando
                    @endif
                </p>
            </div>
        </div>

        {{-- Mensajes últimas 24h --}}
        <div class="rounded-xl border p-4">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                Mensajes salientes — últimas 24 horas
                <span class="font-semibold text-gray-700 dark:text-gray-200">({{ $status['deliveries']['total'] }} en total)</span>
            </p>
            <p class="mb-3 text-xs text-gray-400">Estado FINAL de cada mensaje — no es un embudo acumulado. Un mensaje "Leído" ya pasó por enviado y entregado.</p>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                <div>
                    <p class="text-2xl font-semibold">{{ $status['deliveries']['read'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Leídos</p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">{{ $status['deliveries']['deliveredOnly'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Entregados (sin leer aún)</p>
                </div>
                <div>
                    <p class="text-2xl font-semibold">{{ $status['deliveries']['sentOnly'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Enviados (sin confirmar entrega)</p>
                </div>
                <div>
                    <p class="text-2xl font-semibold {{ $status['deliveries']['failed'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                        {{ $status['deliveries']['failed'] }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Rechazados por Meta</p>
                </div>
                <div>
                    <p class="text-2xl font-semibold {{ $status['deliveries']['apiSendFailures'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                        {{ $status['deliveries']['apiSendFailures'] }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Fallo al enviar (API)</p>
                </div>
            </div>
        </div>

        {{-- Fallas recientes --}}
        <div class="rounded-xl border p-4">
            <p class="mb-3 text-sm font-medium text-gray-500 dark:text-gray-400">Fallas recientes (últimas 48h)</p>

            @if($status['recentFailures']->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Sin fallas registradas. 🎉</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-xs text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4">Fecha</th>
                                <th class="py-2 pr-4">Tipo</th>
                                <th class="py-2 pr-4">Restaurante</th>
                                <th class="py-2 pr-4">Para</th>
                                <th class="py-2 pr-4">Contenido</th>
                                <th class="py-2 pr-4">Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($status['recentFailures'] as $failure)
                                <tr class="border-b last:border-0">
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $failure['created_at']->format('d/m H:i') }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $failure['tipo'] }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $failure['store_name'] }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $failure['customer_phone'] }}</td>
                                    <td class="py-2 pr-4 max-w-xs truncate" title="{{ $failure['content'] }}">{{ $failure['content'] }}</td>
                                    <td class="py-2 pr-4 max-w-xs truncate" title="{{ $failure['reason'] }}">{{ $failure['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <p class="text-xs text-gray-400">Se actualiza automáticamente cada 30 segundos.</p>
    </div>
</x-filament-panels::page>
