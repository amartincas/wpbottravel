<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id',
    'name',
    'description',
    'price',
    'cost_price',
    'stock',
    'type',
    'ai_sales_strategy',
    'faq_context',
    'required_customer_info',
    'meta_ad_ids',
])]
class Product extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price'       => 'decimal:2',
            'cost_price'  => 'decimal:2',
            'stock'       => 'integer',
            'type'        => 'string',
            'meta_ad_ids' => 'array',
        ];
    }

    public function isAvailable(): bool
    {
        if ($this->type === 'service') {
            return $this->stock === 1;
        }
        return $this->stock > 0;
    }

    public function getStockLabel(): string
    {
        if ($this->type === 'service') {
            return $this->stock === 1 ? 'Accepting Clients' : 'Fully Booked';
        }
        if ($this->stock <= 0) return 'Out of Stock';
        if ($this->stock < 5) return 'Low Stock';
        return 'In Stock';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function extras(): HasMany
    {
        return $this->hasMany(ProductExtra::class)->orderBy('sort_order');
    }

    public function availableExtras(): HasMany
    {
        return $this->hasMany(ProductExtra::class)
            ->where('is_available', true)
            ->orderBy('sort_order');
    }

    public function getPrimaryImage(): ?ProductImage
    {
        return $this->images()
            ->where('is_primary', true)
            ->first() ?? $this->images()->first();
    }

    /**
     * Calcula el margen bruto del producto.
     */
    public function getMargin(): float
    {
        if (!$this->cost_price) return 0;
        return (float) $this->price - (float) $this->cost_price;
    }
}
