<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';
    
    protected $fillable = [
        'store_id',
        'name',
        'body_preview',
        'parameters_map',
        'language',
        'type',
        'is_reengagement',
    ];

    protected $casts = [
        'parameters_map'  => 'array',
        'is_reengagement' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
