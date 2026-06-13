<?php

namespace App\Enums;

enum CustomerLeadSource: string
{
    case META_ADS      = 'META_ADS';
    case WHATSAPP_DIRECT = 'WHATSAPP_DIRECT';
    case QR            = 'QR';
    case ORGANIC       = 'ORGANIC';
    case FACEBOOK_PAGE = 'FACEBOOK_PAGE';
    case INSTAGRAM     = 'INSTAGRAM';

    public function label(): string
    {
        return match($this) {
            self::META_ADS        => 'Meta Ads',
            self::WHATSAPP_DIRECT => 'WhatsApp Directo',
            self::QR              => 'Código QR',
            self::ORGANIC         => 'Orgánico',
            self::FACEBOOK_PAGE   => 'Página de Facebook',
            self::INSTAGRAM       => 'Instagram',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::META_ADS        => 'heroicon-o-megaphone',
            self::WHATSAPP_DIRECT => 'heroicon-o-chat-bubble-left-right',
            self::QR              => 'heroicon-o-qr-code',
            self::ORGANIC         => 'heroicon-o-globe-alt',
            self::FACEBOOK_PAGE   => 'heroicon-o-users',
            self::INSTAGRAM       => 'heroicon-o-camera',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
