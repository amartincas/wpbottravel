<?php

namespace App\Filament\Resources\CustomerLeads;

use App\Enums\CustomerLeadStatus;
use App\Filament\Resources\CustomerLeads\Pages\EditCustomerLead;
use App\Filament\Resources\CustomerLeads\Pages\ListCustomerLeads;
use App\Filament\Resources\CustomerLeads\Schemas\CustomerLeadForm;
use App\Filament\Resources\CustomerLeads\Tables\CustomerLeadsTable;
use App\Models\CustomerLead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CustomerLeadResource extends Resource
{
    protected static ?string $model = CustomerLead::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'CRM Leads';

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!Auth::user()?->is_super_admin) {
            $query->where('store_id', Auth::user()?->store_id);
        }

        return $query->with(['store', 'firstProduct', 'lastProduct']);
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerLeadForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerLeadsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerLeads::route('/'),
            'edit'  => EditCustomerLead::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $query = static::getEloquentQuery();
        $newCount = (clone $query)
            ->where('status', CustomerLeadStatus::NEW->value)
            ->count();

        return $newCount > 0 ? (string) $newCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
