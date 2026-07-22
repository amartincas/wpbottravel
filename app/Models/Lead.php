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
    'meeting_point',
    'tour_date',
    'product_service_name',
    'product_name',
    'product_sale_price',
    'product_cost_price',
    'extras_detail',
    'extras_sale_total',
    'extras_cost_total',
    'comments',
    'total_amount',
    'status',
    'summary',
    'is_processed',
    'bot_active',
])]
class Lead extends Model
{
    use HasFactory;

    const STATUS_PENDIENTE = 'pendiente';
    const STATUS_DERIVADO  = 'derivado';
    const STATUS_CERRADO   = 'cerrado';
    const STATUS_CANCELADO = 'cancelado';

    const STATUS_MAP = [
        'derivado'  => self::STATUS_DERIVADO,
        'referred'  => self::STATUS_DERIVADO,
        'cerrado'   => self::STATUS_CERRADO,
        'closed'    => self::STATUS_CERRADO,
        'cancelado' => self::STATUS_CANCELADO,
        'cancelled' => self::STATUS_CANCELADO,
        'canceled'  => self::STATUS_CANCELADO,
    ];

    const STATUS_MESSAGES = [
        self::STATUS_DERIVADO  => '✅ ¡Gracias por tu interés! Un asesor te contactará en breve para confirmar los detalles y el pago de tu reserva.',
        self::STATUS_CERRADO   => '🎉 ¡Tu reserva quedó confirmada! Gracias por elegirnos. ¡Que disfrutes tu experiencia!',
        self::STATUS_CANCELADO => '❌ Tu reserva fue cancelada. Si tienes dudas, escríbenos y te ayudamos.',
    ];

    protected function casts(): array
    {
        return [
            'is_processed'       => 'boolean',
            'bot_active'         => 'boolean',
            'extras_detail'      => 'array',
            'tour_date'          => 'date',
            'product_sale_price' => 'decimal:2',
            'product_cost_price' => 'decimal:2',
            'extras_sale_total'  => 'decimal:2',
            'extras_cost_total'  => 'decimal:2',
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

    public static function resolveStatus(string $text): ?string
    {
        $normalized = strtolower(trim($text));
        return self::STATUS_MAP[$normalized] ?? null;
    }

    public static function statusMessage(string $status): ?string
    {
        return self::STATUS_MESSAGES[$status] ?? null;
    }

    /**
     * Calcula el margen bruto de este lead.
     * Margen = total_amount - product_cost_price - extras_cost_total
     */
    public function getMargin(): float
    {
        $total      = (float) preg_replace('/[^0-9.]/', '', $this->total_amount ?? '0');
        $cost       = (float) ($this->product_cost_price ?? 0);
        $extrasCost = (float) ($this->extras_cost_total ?? 0);
        return $total - $cost - $extrasCost;
    }
}
