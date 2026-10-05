<?php

namespace App\Filament\Pms\Resources\OwnerProfiles;

use App\Filament\Pms\Resources\OwnerProfiles\Pages\CreateOwnerProfile;
use App\Filament\Pms\Resources\OwnerProfiles\Pages\EditOwnerProfile;
use App\Filament\Pms\Resources\OwnerProfiles\Pages\ListOwnerProfiles;
use App\Filament\Pms\Resources\OwnerProfiles\Schemas\OwnerProfileForm;
use App\Filament\Pms\Resources\OwnerProfiles\Tables\OwnerProfilesTable;
use App\Models\OwnerProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OwnerProfileResource extends Resource
{
    protected static ?string $model = OwnerProfile::class;

    protected static bool $isGloballySearchable = true;

    protected static int $globalSearchResultsLimit = 10;

    public static function getGloballySearchableAttributes(): array
    {
        return ['party.name', 'identification_number', 'unified_number', 'trn'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('party');
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->party?->name ?? static::getModelLabel());
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([__('Identification Number') => $record->identification_number]);
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Ownership';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('Owner');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Owners');
    }

    public static function getNavigationLabel(): string
    {
        return __('Owners');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Ownership');
    }

    public static function form(Schema $schema): Schema
    {
        return OwnerProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OwnerProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOwnerProfiles::route('/'),
            'create' => CreateOwnerProfile::route('/create'),
            'edit' => EditOwnerProfile::route('/{record}/edit'),
        ];
    }
}
