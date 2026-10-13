<?php

namespace App\Filament\Mms\Resources\LetterTemplates\Tables;

use App\Enums\LetterTemplateCategories;
use App\Models\Court;
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
                    ->searchable(),
                TextColumn::make('subject')
                    ->searchable(),
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
                    ->placeholder(__('All matter types'))
                    ->limitList(5)
                    ->expandableLimitedList(),
                TextColumn::make('courts.name')
                    ->label(__('Courts'))
                    ->badge()
                    ->color('gray')
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
                    ->options(fn () => Type::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(fn (Builder $q) => $q->whereDoesntHave('types')->orWhereHas('types', fn (Builder $t) => $t->whereKey($data['value'])))
                        : $query),
                SelectFilter::make('court')
                    ->label(__('Court'))
                    ->options(fn () => Court::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->forCourt((int) $data['value']) : $query),
                // Written to these entities (police, the central bank …).
                SelectFilter::make('entities')
                    ->label(__('External entity'))
                    ->relationship('entities', 'name', fn (Builder $query) => $query->withRole('external'))
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
