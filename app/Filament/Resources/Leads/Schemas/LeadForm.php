<?php

namespace App\Filament\Resources\Leads\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use App\Models\Lead;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('customer_name')
                    ->label('Nombre Completo')
                    ->required()
                    ->columnSpan(2),
                TextInput::make('customer_phone')
                    ->label('WhatsApp')
                    ->tel()
                    ->required()
                    ->columnSpan(2),
                Textarea::make('meeting_point')
                    ->label('Punto de Encuentro / Referencia')
                    ->rows(3)
                    ->columnSpanFull(),
                DatePicker::make('tour_date')
                    ->label('Fecha del Tour')
                    ->columnSpan(2),
                TextInput::make('product_service_name')
                    ->label('Tour / Servicio')
                    ->columnSpan(2),
                TextInput::make('total_amount')
                    ->label('Valor Total')
                    ->helperText('Valor total de la reserva incluyendo extras')
                    ->columnSpan(2),
                Select::make('status')
                    ->label('Estado de la Reserva')
                    ->options([
                        Lead::STATUS_PENDIENTE => '⏳ Pendiente',
                        Lead::STATUS_DERIVADO  => '🧑‍💼 Derivado a asesor',
                        Lead::STATUS_CERRADO   => '🎉 Cerrado',
                        Lead::STATUS_CANCELADO => '❌ Cancelado',
                    ])
                    ->default(Lead::STATUS_PENDIENTE)
                    ->columnSpan(2),
                Textarea::make('summary')
                    ->label('Resumen de la Reserva')
                    ->rows(4)
                    ->required()
                    ->columnSpanFull(),
                Toggle::make('is_processed')
                    ->label('Marcar como Procesado')
                    ->columnSpanFull(),
                Toggle::make('bot_active')
                    ->label('Bot Activo para este Cliente')
                    ->helperText('Desactiva para atención manual.')
                    ->default(true)
                    ->columnSpanFull(),
                \Filament\Forms\Components\Placeholder::make('recent_conversation')
                    ->label('Conversación Reciente')
                    ->content(function ($record) {
                        if (!$record) {
                            return view('filament.components.chat-history', [
                                'record'   => null,
                                'messages' => collect([]),
                            ]);
                        }

                        $messages = \App\Models\WhatsAppMessage::where('store_id', $record->store_id)
                            ->where('customer_phone', $record->customer_phone)
                            ->orderBy('created_at', 'desc')
                            ->limit(15)
                            ->get()
                            ->reverse();

                        return view('filament.components.chat-history', [
                            'record'   => $record,
                            'messages' => $messages,
                        ]);
                    })
                    ->columnSpanFull(),
            ]);
    }
}
