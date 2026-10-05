<?php

namespace App\Filament\Pms\Resources\Properties;

use App\Filament\Pms\Resources\Properties\Pages\CreateProperty;
use App\Filament\Pms\Resources\Properties\Pages\EditProperty;
use App\Filament\Pms\Resources\Properties\Pages\ListProperties;
use App\Filament\Pms\Resources\Properties\RelationManagers\UnitsRelationManager;
use App\Filament\Pms\Resources\Properties\Schemas\PropertyForm;
use App\Filament\Pms\Resources\Properties\Tables\PropertiesTable;
use App\Models\Property;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static bool $isGloballySearchable = true;

    protected static int $globalSearchResultsLimit = 10;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'property_number', 'title_deed_number', 'plot_number'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([__('Property No.') => $record->property_number]);
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|UnitEnum|null $navigationGroup = 'Properties';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Property');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Properties');
    }

    public static function getNavigationLabel(): string
    {
        return __('Properties');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Properties');
    }

    public static function form(Schema $schema): Schema
    {
        return PropertyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PropertiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            UnitsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProperties::route('/'),
            'create' => CreateProperty::route('/create'),
            'edit' => EditProperty::route('/{record}/edit'),
        ];
    }
}
