<?php

namespace App\Filament\Resources\CustomerLeads\Tables;

use App\Enums\CustomerLeadSource;
use App\Enums\CustomerLeadStatus;
use Filament\Actions\EditAction;  // Sin Tables
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CustomerLeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Sin nombre'),

                TextColumn::make('customer_phone')
                    ->label('Teléfono')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof CustomerLeadStatus
                        ? $state->label()
                        : CustomerLeadStatus::from($state)->label())
                    ->color(fn ($state) => $state instanceof CustomerLeadStatus
                        ? $state->color()
                        : CustomerLeadStatus::from($state)->color())
                    ->sortable(),

                TextColumn::make('first_source')
                    ->label('Origen')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof CustomerLeadSource
                        ? $state->label()
                        : ($state ? CustomerLeadSource::from($state)->label() : 'Desconocido'))
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('total_orders')
                    ->label('Pedidos')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('customer_lifetime_value')
                    ->label('CLV')
                    ->money('COP', locale: 'es_CO')
                    ->sortable(),

                TextColumn::make('firstProduct.name')
                    ->label('Primer Producto')
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('lastProduct.name')
                    ->label('Último Producto')
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('last_contact_at')
                    ->label('Último Contacto')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->timezone('America/Bogota'),

                TextColumn::make('last_order_at')
                    ->label('Última Compra')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->timezone('America/Bogota')
                    ->placeholder('Sin compras'),

                TextColumn::make('store.name')
                    ->label('Operador')
                    ->sortable()
                    ->visible(fn () => Auth::user()?->is_super_admin),
            ])
            ->defaultSort('last_contact_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(CustomerLeadStatus::options()),

                SelectFilter::make('first_source')
                    ->label('Origen')
                    ->options(CustomerLeadSource::options()),

                SelectFilter::make('store_id')
                    ->label('Operador')
                    ->relationship('store', 'name')
                    ->visible(fn () => Auth::user()?->is_super_admin),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->striped()
            ->paginated([15, 25, 50]);
    }
}
