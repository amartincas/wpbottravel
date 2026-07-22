<?php

namespace App\Filament\Resources\Stores\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required(),

                Select::make('status')
                    ->label('Estado del Store')
                    ->options([
                        'active'   => '✅ Activo',
                        'inactive' => '⏸️ Inactivo',
                        'demo'     => '🎯 Demo',
                    ])
                    ->default('active')
                    ->required()
                    ->helperText('Demo: simulación completa sin persistir reservas en BD'),
                Select::make('personality_type')
                    ->options(['vendedor' => 'Vendedor', 'soporte' => 'Soporte', 'asesor' => 'Asesor'])
                    ->required(),
                Textarea::make('system_prompt')
                    ->required()
                    ->columnSpanFull(),

                // =====================================================
                // Notificación de nuevos leads al asesor
                // =====================================================
                TextInput::make('advisor_whatsapp')
                    ->label('WhatsApp del Asesor')
                    ->placeholder('573001234567')
                    ->helperText('Número con código de país, sin espacios ni símbolos. Ej: 573001234567')
                    ->columnSpanFull(),
                TextInput::make('advisor_notification_template')
                    ->label('Plantilla Meta para Notificación al Asesor')
                    ->placeholder('nueva_reserva')
                    ->helperText('Nombre exacto de la plantilla aprobada en Meta Business Manager')
                    ->columnSpanFull(),
                TextInput::make('advisor_notification_template_lang')
                    ->label('Idioma de la Plantilla')
                    ->placeholder('es_CO')
                    ->default('es_CO')
                    ->helperText('Código de idioma BCP-47. Ej: es_CO, en_US, es_MX'),
            ]);
    }
}