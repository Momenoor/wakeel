<?php

namespace App\Filament\Mms\Resources\CalendarEvents\Tables;

use App\Filament\Mms\Actions\Calendar\CalendarMatterActions;
use App\Filament\Mms\Actions\Calendar\CreateBulkCalendarEventAction;
use App\Filament\Mms\Actions\Calendar\CreateSingleCalendarEventAction;
use App\Filament\Mms\Actions\Calendar\SyncToOutlookAction;
use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventBulkInfolist;
use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventInfolist;
use App\Models\CalendarEvent;
use App\Services\MMS\OutlookCalendarService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CalendarEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => $record->location ?: null),

                TextColumn::make('matters')
                    ->label(__('Matter'))
                    ->bulleted()
                    // Number and year are the linked matters' columns, not the
                    // event's: search through the link. "1957/2024" matches
                    // that matter exactly; "1957" any number or year with it.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $search = trim($search);

                        return $query->orWhereHas('matters', function (Builder $matters) use ($search): void {
                            if (preg_match('~^(\d+)\s*/\s*(\d{4})$~', $search, $m)) {
                                $matters->where('matters.number', $m[1])->where('matters.year', $m[2]);

                                return;
                            }

                            $matters->where(fn (Builder $q) => $q
                                ->where('matters.number', 'like', "%{$search}%")
                                ->orWhere('matters.year', 'like', "%{$search}%"));
                        });
                    })
                    ->formatStateUsing(function ($state) {
                        // $state is the collection of related Matter models
                        return "{$state->number}/{$state->year}";
                    }),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'single' => 'primary',
                        'bulk' => 'warning',
                    }),
                TextColumn::make('matter.number')
                    ->label(__('Matter'))
                    ->getStateUsing(fn ($record) => $record->matter
                        ? $record->matter->year.'/'.$record->matter->number
                        : '—'
                    )
                    ->searchable()
                    ->sortable()
                    ->visible(fn ($record) => $record?->type === 'single'),
                TextColumn::make('start_datetime')
                    ->label(__('Start At'))
                    ->timezone(config('app.timezone'))
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('end_datetime')
                    ->label(__('End At'))
                    ->timezone(config('app.timezone'))
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                IconColumn::make('synced_to_outlook')
                    ->boolean()
                    ->label(__('Synced')),
                IconColumn::make('is_teams_meeting')
                    ->boolean()
                    ->label(__('Teams')),
                TextColumn::make('online_meeting_url')
                    ->label(__('Link'))
                    ->icon(Heroicon::VideoCamera)
                    ->formatStateUsing(fn ($state) => $state ? __('Join') : '—')
                    ->url(fn ($record) => $record->online_meeting_url)
                    ->color('info')
                    ->openUrlInNewTab(),

                TextColumn::make('createdBy.name')
                    ->label(__('Created By'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('start_datetime')
            ->filters([
                Filter::make('upcoming')
                    ->label(__('Upcoming'))
                    ->query(fn (Builder $query) => $query->where('start_datetime', '>=', now()))
                    ->default(),
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options([
                        'single' => __('Single'),
                        'bulk' => __('Bulk'),
                    ]),
                TernaryFilter::make('synced_to_outlook')
                    ->label(__('Synced to Outlook')),
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateSingleCalendarEventAction::make('createSingle')
                    ->visible(fn () => auth()->user()->can('CreateSingle:CalendarEvent')),
                CreateBulkCalendarEventAction::make('createBulk')
                    ->visible(fn () => auth()->user()->can('CreateBulk:CalendarEvent')),
                // Same permission the old one-off import had.
                CalendarMatterActions::syncWithOutlook()
                    ->visible(fn () => auth()->user()->can('ImportFromOutlook:CalendarEvent') && app(OutlookCalendarService::class)->isConfigured()),
                CalendarMatterActions::linkAllFromTitles()
                    ->visible(fn () => auth()->user()->can('ImportFromOutlook:CalendarEvent')),
            ])
            ->recordActions([
                SyncToOutlookAction::make()
                    ->visible(fn ($record) => $record instanceof CalendarEvent && auth()->user()->can('SyncToOutlook:CalendarEvent') && ! $record->synced_to_outlook),
                CalendarMatterActions::linkMatters()
                    ->visible(fn ($record) => auth()->user()->can('update', $record)),
                CalendarMatterActions::ignoreReferences(),
                ViewAction::make()->schema(fn (Schema $schema, $record) => $record->type == 'single' ? CalendarEventInfolist::configure($schema) : CalendarEventBulkInfolist::configure($schema))->iconButton(),
                EditAction::make()->iconButton()->schema(fn (Schema $schema) => CalendarEventForm::configure($schema)),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    CalendarMatterActions::linkFromTitlesBulk(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
