<?php

namespace App\Filament\Resources\Leads\Schemas;

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
                Textarea::make('delivery_address_or_location')
                    ->label('Dirección de Entrega')
                    ->rows(3)
                    ->columnSpanFull(),
                TextInput::make('location')
                    ->label('Coordenadas GPS')
                    ->placeholder('3.4516,-76.5320')
                    ->helperText('Coordenadas enviadas por el cliente via WhatsApp (lat,lng)')
                    ->columnSpanFull(),
                TextInput::make('product_service_name')
                    ->label('Producto / Servicio')
                    ->columnSpan(2),
                TextInput::make('total_amount')
                    ->label('Valor Total')
                    ->helperText('Valor total del pedido incluyendo extras')
                    ->columnSpan(2),
                Select::make('status')
                    ->label('Estado del Pedido')
                    ->options([
                        Lead::STATUS_PENDIENTE      => '⏳ Pendiente',
                        Lead::STATUS_ACEPTADO       => '✅ Aceptado',
                        Lead::STATUS_EN_PREPARACION => '🍗 En Preparación',
                        Lead::STATUS_LISTO          => '📦 Listo',
                        Lead::STATUS_ENTREGADO      => '🎉 Entregado',
                        Lead::STATUS_CANCELADO      => '❌ Cancelado',
                    ])
                    ->default(Lead::STATUS_PENDIENTE)
                    ->columnSpan(2),
                Textarea::make('summary')
                    ->label('Resumen del Pedido')
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
