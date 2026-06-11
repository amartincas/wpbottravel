<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique('users', 'email', ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->hidden(fn (string $operation) => $operation === 'edit')
                    ->helperText('Password for the user account'),
                TextInput::make('whatsapp')
                    ->label('WhatsApp')
                    ->placeholder('573001234567')
                    ->helperText('Número con código de país, sin espacios ni símbolos. Permite consultar reportes al bot.')
                    ->maxLength(20),
                Select::make('store_id')
                    ->relationship('store', 'name')
                    ->required()
                    ->label('Assign Store')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->disabled(fn (string $operation) => $operation === 'edit'),
                Checkbox::make('is_super_admin')
                    ->label('Grant Superuser Access')
                    ->helperText('Superusers can manage all stores, users, products, and leads.'),
            ]);
    }
}
