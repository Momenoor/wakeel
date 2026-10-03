<?php

namespace App\Filament\Mms\Resources\LetterItems;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Clusters\Templates;
use App\Filament\Mms\Resources\LetterItems\Pages\ManageLetterItems;
use App\Models\LetterItem;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * The library of reusable lines for letters' numbered lists — documents
 * to request, instructions to give — in groups, reorderable by dragging.
 */
class LetterItemResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = LetterItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 2;

    protected static ?string $cluster = Templates::class;

    public static function moduleGateKey(): string
    {
        return 'mms_communications';
    }

    public static function getModelLabel(): string
    {
        return __('Letter item');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Letter items library');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('group')
                ->label(__('Group'))
                ->required()
                ->datalist(fn () => LetterItem::groups())
                ->placeholder('مستندات مطلوبة من الشركة تحت التصفية')
                ->helperText(__('Pick an existing group or type a new one.')),
            Select::make('types')
                ->label(__('Matter types'))
                ->relationship('types', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->placeholder(__('All matter types')),
            Textarea::make('text')
                ->label(__('Text'))
                ->required()
                ->rows(3)
                ->extraInputAttributes(['dir' => 'auto'])
                ->columnSpanFull(),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultGroup(Group::make('group')->label(__('Group'))->collapsible())
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('text')->label(__('Text'))->wrap()->searchable(),
                TextColumn::make('types.name')->label(__('Matter types'))->badge()->placeholder(__('All matter types'))->toggleable(),
                ToggleColumn::make('is_active')->label(__('Active')),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label(__('Group'))
                    ->options(fn () => array_combine(LetterItem::groups(), LetterItem::groups()) ?: []),
                SelectFilter::make('types')
                    ->label(__('Matter types'))
                    ->relationship('types', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLetterItems::route('/'),
        ];
    }
}
