<?php

namespace App\Enums;

enum CustomerLeadStatus: string
{
    case NEW            = 'NEW';
    case CONTACTED      = 'CONTACTED';
    case INTERESTED     = 'INTERESTED';
    case CUSTOMER       = 'CUSTOMER';
    case REPEAT_CUSTOMER = 'REPEAT_CUSTOMER';
    case INACTIVE       = 'INACTIVE';
    case BLOCKED        = 'BLOCKED';

    public function label(): string
    {
        return match($this) {
            self::NEW             => 'Nuevo',
            self::CONTACTED       => 'Contactado',
            self::INTERESTED      => 'Interesado',
            self::CUSTOMER        => 'Cliente',
            self::REPEAT_CUSTOMER => 'Cliente Recurrente',
            self::INACTIVE        => 'Inactivo',
            self::BLOCKED         => 'Bloqueado',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::NEW             => 'gray',
            self::CONTACTED       => 'info',
            self::INTERESTED      => 'warning',
            self::CUSTOMER        => 'success',
            self::REPEAT_CUSTOMER => 'primary',
            self::INACTIVE        => 'danger',
            self::BLOCKED         => 'danger',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::NEW             => 'heroicon-o-user-plus',
            self::CONTACTED       => 'heroicon-o-chat-bubble-left',
            self::INTERESTED      => 'heroicon-o-star',
            self::CUSTOMER        => 'heroicon-o-shopping-bag',
            self::REPEAT_CUSTOMER => 'heroicon-o-arrow-path',
            self::INACTIVE        => 'heroicon-o-clock',
            self::BLOCKED         => 'heroicon-o-no-symbol',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
