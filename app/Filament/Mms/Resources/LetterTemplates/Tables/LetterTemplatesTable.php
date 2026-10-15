<?php

namespace App\Filament\Mms\Resources\LetterTemplates\Tables;

use App\Enums\LetterTemplateCategories;
use App\Models\Court;
use App\Models\LetterTemplate;
use App\Models\Type;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LetterTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('subject')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('locale')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
                IconColumn::make('is_default')
                    ->boolean(),
                TextColumn::make('category')
                    ->badge()
                    ->searchable(),
                TextColumn::make('types.name')
                    ->label(__('Matter types'))
                    ->badge()
                    // "All except" says so in front of the types it leaves out.
                    ->prefix(fn (LetterTemplate $record): ?string => $record->types_except ? __('All except').': ' : null)
                    ->color(fn (LetterTemplate $record): string => $record->types_except ? 'danger' : 'primary')
                    ->placeholder(__('All matter types'))
                    ->listWithLineBreaks()
                    ->limitList(5)
                    ->expandableLimitedList(),
                TextColumn::make('courts.name')
                    ->label(__('Courts'))
                    ->badge()
                    ->color('gray')
                    ->listWithLineBreaks()
                    ->placeholder(__('All courts'))
                    ->limitList(5)
                    ->expandableLimitedList(),
                TextColumn::make('entities.name')
                    ->label(__('External entities'))
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->limitList(5)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // A court or a type: the templates for it — those for every one included.
            ->filters([
                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->options(LetterTemplateCategories::class)
                    ->multiple(),
                SelectFilter::make('type')
                    ->label(__('Matter type'))
                    ->options(fn() => Type::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn(Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->offeredForType((int) $data['value'])
                        : $query),
                SelectFilter::make('court')
                    ->label(__('Court'))
                    ->options(fn() => Court::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn(Builder $query, array $data) => filled($data['value'] ?? null) ? $query->forCourt((int)$data['value']) : $query),
                // Written to these entities (police, the central bank …).
                SelectFilter::make('entities')
                    ->label(__('External entity'))
                    ->relationship('entities', 'name', fn(Builder $query) => $query->withRole('external'))
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
