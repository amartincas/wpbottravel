<?php

namespace App\Filament\Resources\Stores\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StoreWizardForm
{
    // Persona templates for system prompts
    private static array $personaTemplates = [
        'vendedor' => 'You are a friendly sales assistant for a tour operator on WhatsApp. Your role is to help travelers find the perfect tour or activity for their trip, share full details (itinerary, price, availability), and when they are ready to move forward, hand them off to an advisor to close payment and booking details. Be helpful, positive, and focused on matching travelers with experiences that fit what they are looking for.',
        'soporte' => 'You are a professional customer support agent for a tour operator. Your role is to resolve traveler questions about bookings, tours, and logistics quickly and efficiently. Be empathetic, thorough, and always aim to exceed expectations.',
        'asesor' => 'You are a helpful travel advisor. Your role is to guide travelers through the available tours and activities, answer their questions, and help them decide which experience best fits their trip before connecting them with an advisor to confirm payment. Be knowledgeable, friendly, and focus on understanding their needs.',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // === AI PERSONA ===
                Select::make('personality_type')
                    ->label('Persona Type')
                    ->options([
                        'vendedor' => 'Sales Representative (Vendedor)',
                        'soporte' => 'Support Agent (Soporte)',
                        'asesor' => 'Business Advisor (Asesor)',
                    ])
                    ->required()
                    ->helperText('Select the personality that best matches your use case.')
                    ->reactive()
                    ->columnSpanFull(),

                Textarea::make('system_prompt')
                    ->label('System Prompt')
                    ->required()
                    ->rows(6)
                    ->helperText('This prompt defines how the AI behaves. It was auto-filled based on your persona selection.')
                    ->columnSpanFull()
                    ->default(function (Get $get) {
                        $personality = $get('personality_type');
                        return self::$personaTemplates[$personality] ?? '';
                    })
                    ->reactive(),

                // =====================================================
                // Estado del Store y Notificación al Asesor
                // =====================================================
                \Filament\Forms\Components\Select::make('status')
                    ->label('Estado del Store')
                    ->options([
                        'active'   => '✅ Activo',
                        'inactive' => '⏸️ Inactivo',
                        'demo'     => '🎯 Demo',
                    ])
                    ->default('active')
                    ->required()
                    ->helperText('Demo: simulación completa sin persistir reservas en BD')
                    ->columnSpanFull(),

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
                    ->helperText('Código de idioma BCP-47. Ej: es_CO, en_US, es_MX')
                    ->columnSpanFull(),
            ]);
    }
}
