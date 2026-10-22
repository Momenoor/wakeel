<?php

namespace App\Filament\Mms\Resources\HrListItems;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\HrListItems\Pages\ManageHrListItems;
use App\Filament\Mms\Resources\HrListItems\Schemas\HrListItemForm;
use App\Models\HrListItem;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Human Resources → Designations: the office's own list, each in Arabic and English, chosen from on an
 * employee's profile.
 */
class HrListItemResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = HrListItem::class;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 9;

    public static function moduleGateKey(): string
    {
        return 'mms_payroll';
    }

    public static function getModelLabel(): string
    {
        return __('List entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Designations');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Human Resources');
    }

    public static function form(Schema $schema): Schema
    {
        return HrListItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_ar')->label(__('Name in Arabic'))->searchable()->sortable(),
                TextColumn::make('name_en')->label(__('Name in English'))->searchable()->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->ofKind(HrListItem::DESIGNATION))
            ->defaultSort('name_ar')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageHrListItems::route('/')];
    }
}
