<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'name',
    'description',
    'cost_price',
    'sale_price',
    'is_available',
    'sort_order',
])]
class ProductExtra extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cost_price'       => 'decimal:2',
            'sale_price'       => 'decimal:2',
            'is_available'     => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Busca extras disponibles por nombre para un producto dado.
     * Usado para construir el snapshot en el lead.
     */
    public static function findByName(int $productId, string $name): ?self
    {
        return static::where('product_id', $productId)
            ->where('is_available', true)
            ->where('name', 'like', '%' . $name . '%')
            ->first();
    }

    /**
     * Retorna todos los extras disponibles de un producto
     * formateados para el prompt de la IA.
     */
    public static function forPrompt(int $productId): string
    {
        $extras = static::where('product_id', $productId)
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->get();

        if ($extras->isEmpty()) {
            return '';
        }

        $lines = ['  ➕ Extras disponibles:'];
        foreach ($extras as $extra) {
            $lines[] = '    • ' . $extra->name
                . ' — $' . number_format($extra->sale_price, 0, ',', '.')
                . ($extra->description ? ' (' . $extra->description . ')' : '');
        }

        return implode("\n", $lines);
    }
}
