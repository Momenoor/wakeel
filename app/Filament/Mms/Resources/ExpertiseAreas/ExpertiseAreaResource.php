<?php

namespace App\Filament\Mms\Resources\ExpertiseAreas;

use App\Filament\Mms\Resources\ExpertiseAreas\Pages\ManageExpertiseAreas;
use App\Models\ExpertiseArea;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The areas of expertise offered for experts on the party form. Adding one
 * here makes it available straight away; hiding one takes it off the list
 * without touching the experts who already have it.
 */
class ExpertiseAreaResource extends Resource
{
    protected static ?string $model = ExpertiseArea::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Expertise Area');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expertise Areas');
    }

    public static function getNavigationLabel(): string
    {
        return __('Expertise Areas');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_en')
                ->label(__('Name (English)'))
                ->required()
                ->maxLength(255),
            TextInput::make('name_ar')
                ->label(__('Name (Arabic)'))
                ->maxLength(255),
            TextInput::make('sort')
                ->label(__('Order'))
                ->numeric()
                ->minValue(0)
                ->default(fn (): int => (int) ExpertiseArea::max('sort') + 1),
            Toggle::make('is_active')
                ->label(__('Offered on the party form'))
                ->default(true)
                ->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name_en')
                    ->label(__('Name (English)'))
                    ->searchable(),
                TextColumn::make('name_ar')
                    ->label(__('Name (Arabic)'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('experts')
                    ->label(__('Experts'))
                    ->state(fn (ExpertiseArea $record): int => $record->partiesCount())
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                // Only an area no expert has; one in use is hidden instead.
                DeleteAction::make()
                    ->iconButton()
                    ->before(function (ExpertiseArea $record, DeleteAction $action): void {
                        if ($record->partiesCount() > 0) {
                            Notification::make()
                                ->danger()
                                ->title(__('Could not continue'))
                                ->body(__('Experts have this area. Switch it off instead, so it is no longer offered.'))
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->headerActions([
                CreateAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpertiseAreas::route('/'),
        ];
    }
}
