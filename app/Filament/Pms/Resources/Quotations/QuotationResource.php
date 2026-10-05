<?php

namespace App\Filament\Pms\Resources\Quotations;

use App\Filament\Pms\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Pms\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Pms\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Pms\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Pms\Resources\Quotations\Schemas\QuotationForm;
use App\Filament\Pms\Resources\Quotations\Schemas\QuotationInfolist;
use App\Filament\Pms\Resources\Quotations\Tables\QuotationsTable;
use App\Models\Quotation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static bool $isGloballySearchable = true;

    protected static int $globalSearchResultsLimit = 5;

    public static function getGloballySearchableAttributes(): array
    {
        return ['party.name', 'units.unit_number'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('party')->latest('id');
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return static::getModelLabel().' #'.$record->getKey().' — '.($record->party?->name ?? '');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([__('Status') => $record->status?->getLabel()]);
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static string|UnitEnum|null $navigationGroup = 'Leasing';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('Quotation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Quotations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Quotations');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Leasing');
    }

    public static function form(Schema $schema): Schema
    {
        return QuotationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QuotationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'view' => ViewQuotation::route('/{record}'),
            'edit' => EditQuotation::route('/{record}/edit'),
        ];
    }
}
