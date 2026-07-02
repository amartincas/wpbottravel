<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'store_id',
        'customer_phone',
        'role',
        'content',
        'wamid',
        'delivery_status',
        'delivery_error',
    ];

    /**
     * Get the store that owns this message.
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
