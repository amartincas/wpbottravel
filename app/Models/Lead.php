<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'store_id',
    'customer_phone',
    'customer_name',
    'delivery_address_or_location',
    'location',
    'product_service_name',
    'total_amount',
    'status',
    'summary',
    'is_processed',
    'bot_active',
])]
class Lead extends Model
{
    use HasFactory;

    // Estados válidos del ciclo de vida del pedido
    const STATUS_PENDIENTE       = 'pendiente';
    const STATUS_ACEPTADO        = 'aceptado';
    const STATUS_LISTO           = 'listo';
    const STATUS_DESPACHADO      = 'despachado';
    const STATUS_ENTREGADO       = 'entregado';
    const STATUS_CANCELADO       = 'cancelado';

    // Mapa de texto recibido del restaurante → status interno
    // Usado para interpretar botones y comandos de texto
    const STATUS_MAP = [
        'aceptado'       => self::STATUS_ACEPTADO,
        'accepted'       => self::STATUS_ACEPTADO,
        'listo'          => self::STATUS_LISTO,
        'ready'          => self::STATUS_LISTO,
        'despachado'     => self::STATUS_DESPACHADO,
        'shipped'        => self::STATUS_DESPACHADO,
        'entregado'      => self::STATUS_ENTREGADO,
        'delivered'      => self::STATUS_ENTREGADO,
        'cancelado'      => self::STATUS_CANCELADO,
        'cancelled'      => self::STATUS_CANCELADO,
        'canceled'       => self::STATUS_CANCELADO,
    ];

    // Mensajes que el bot envía al cliente por cada cambio de estado
    const STATUS_MESSAGES = [
        self::STATUS_ACEPTADO       => '✅ ¡Buenas noticias! El restaurante recibió tu pedido y ya inició la preparación. Te avisamos cuando esté listo.',
        self::STATUS_LISTO          => '📦 ¡Tu pedido está listo! Ya salió para entrega.',
        self::STATUS_DESPACHADO     => '🚚 Tu pedido ha sido despachado. Pronto estará en camino.',
        self::STATUS_ENTREGADO      => '🎉 ¡Tu pedido fue entregado! Gracias por tu compra. ¡Que lo disfrutes!',
        self::STATUS_CANCELADO      => '❌ Tu pedido fue cancelado. Si tienes dudas, escríbenos y te ayudamos.',
    ];

    protected function casts(): array
    {
        return [
            'is_processed' => 'boolean',
            'bot_active'   => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function markAsProcessed(): void
    {
        $this->update(['is_processed' => true]);
    }

    public function isProcessed(): bool
    {
        return $this->is_processed === true;
    }

    public static function unprocessed($storeId)
    {
        return static::where('store_id', $storeId)
            ->where('is_processed', false)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Resuelve el status interno a partir del texto recibido del restaurante.
     * Retorna null si el texto no corresponde a ningún estado conocido.
     */
    public static function resolveStatus(string $text): ?string
    {
        $normalized = strtolower(trim($text));
        return self::STATUS_MAP[$normalized] ?? null;
    }

    /**
     * Obtiene el mensaje de notificación al cliente para un status dado.
     */
    public static function statusMessage(string $status): ?string
    {
        return self::STATUS_MESSAGES[$status] ?? null;
    }
}
