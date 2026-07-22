<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'personality_type',
    'system_prompt',
    'status',
    'advisor_whatsapp',
    'advisor_notification_template',
    'advisor_notification_template_lang',
])]
class Store extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'personality_type' => 'string',
        ];
    }

    /**
     * The 7 WhatsApp/AI credential fields below no longer live on `stores` —
     * they moved to the single platform-wide WhatsAppPlatformSetting row.
     * These read-only accessors keep every existing `$store->wa_access_token`
     * style call site working unchanged. Deliberately not added to $appends
     * so secrets never leak into toArray()/JSON serialization of a Store.
     */
    protected function aiProvider(): Attribute
    {
        return Attribute::make(get: fn () => WhatsAppPlatformSetting::current()->ai_provider);
    }

    protected function aiModel(): Attribute
    {
        return Attribute::make(get: fn () => WhatsAppPlatformSetting::current()->ai_model);
    }

    protected function aiApiKey(): Attribute
    {
        return Attribute::make(get: fn () => WhatsAppPlatformSetting::current()->ai_api_key);
    }

    protected function waAccessToken(): Attribute
    {
        return Attribute::make(get: fn () => WhatsAppPlatformSetting::current()->wa_access_token);
    }

    protected function waPhoneNumberId(): Attribute
    {
        return Attribute::make(get: fn () => WhatsAppPlatformSetting::current()->wa_phone_number_id);
    }

    protected function waBusinessAccountId(): Attribute
    {
        return Attribute::make(get: fn () => WhatsAppPlatformSetting::current()->wa_business_account_id);
    }

    protected function waVerifyToken(): Attribute
    {
        return Attribute::make(get: fn () => WhatsAppPlatformSetting::current()->wa_verify_token);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Verifica si el store tiene configurada la notificación al asesor.
     */
    public function hasAdvisorNotification(): bool
    {
        return !empty($this->advisor_whatsapp)
            && !empty($this->advisor_notification_template);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDemo(): bool
    {
        return $this->status === 'demo';
    }

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }
}
