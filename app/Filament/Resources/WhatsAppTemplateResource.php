<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WhatsAppTemplates\Pages\CreateWhatsAppTemplate;
use App\Filament\Resources\WhatsAppTemplates\Pages\EditWhatsAppTemplate;
use App\Filament\Resources\WhatsAppTemplates\Pages\ListWhatsAppTemplates;
use App\Filament\Resources\WhatsAppTemplates\Schemas\WhatsAppTemplateForm;
use App\Filament\Resources\WhatsAppTemplates\Tables\WhatsAppTemplatesTable;
use App\Models\WhatsAppTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class WhatsAppTemplateResource extends Resource
{
    protected static ?string $model = WhatsAppTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentDuplicate;

    protected static ?string $navigationLabel = 'Plantillas WA';

    protected static string|\UnitEnum|null $navigationGroup = 'WhatsApp';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! Auth::user()?->is_super_admin) {
            $query->where('store_id', Auth::user()?->store_id);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return WhatsAppTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WhatsAppTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListWhatsAppTemplates::route('/'),
            'create' => CreateWhatsAppTemplate::route('/create'),
            'edit'   => EditWhatsAppTemplate::route('/{record}/edit'),
        ];
    }
}
