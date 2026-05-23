<?php

namespace App\Filament\Resources\WhatsAppTemplates\Tables;

use App\Models\Store;
use App\Models\WhatsAppTemplate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class WhatsAppTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('name')
                    ->label('Nombre Tecnico')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Nombre copiado')
                    ->fontFamily('mono')
                    ->weight(FontWeight::Medium),

                TextColumn::make('store.name')
                    ->label('Tienda')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                TextColumn::make('type')
                    ->label('Categoria')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'utility'        => 'success',
                        'marketing'      => 'warning',
                        'authentication' => 'danger',
                        default          => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'utility'        => 'Utility',
                        'marketing'      => 'Marketing',
                        'authentication' => 'Authentication',
                        default          => $state,
                    }),

                TextColumn::make('language')
                    ->label('Idioma')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('body_preview')
                    ->label('Vista Previa')
                    ->limit(60)
                    ->tooltip(fn (WhatsAppTemplate $record): string => $record->body_preview)
                    ->color('gray'),

                ToggleColumn::make('is_reengagement')
                    ->label('Re-engagement')
                    ->onColor('success')
                    ->offColor('gray')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->defaultSort('created_at', 'desc')
            ->filters([

                Filter::make('name')
                    ->label('Buscar por nombre')
                    ->form([
                        TextInput::make('name')
                            ->label('Nombre tecnico')
                            ->placeholder('ej: shipping_update'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['name'] ?? null,
                            fn (Builder $q, string $value): Builder =>
                                $q->where('name', 'like', "%{$value}%")
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        return filled($data['name'] ?? null)
                            ? 'Nombre: ' . $data['name']
                            : null;
                    }),

                SelectFilter::make('store_id')
                    ->label('Tienda')
                    ->options(function (): array {
                        if (Auth::user()?->is_super_admin) {
                            return Store::orderBy('name')->pluck('name', 'id')->toArray();
                        }
                        return Store::where('id', Auth::user()?->store_id)
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->placeholder('Todas las tiendas'),

                SelectFilter::make('type')
                    ->label('Categoria')
                    ->options([
                        'utility'        => 'Utility',
                        'marketing'      => 'Marketing',
                        'authentication' => 'Authentication',
                    ])
                    ->placeholder('Todas las categorias'),

            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
