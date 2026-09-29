<?php

namespace App\Filament\Mms\Widgets;

use App\Filament\Mms\Actions\Calendar\CalendarMatterActions;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\CalendarEvent;
use App\Services\MMS\Calendar\UnmatchedEventReferences;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;

/**
 * Calendar events that name a matter number no matter in the system has —
 * a matter still to be entered, or a mistyped title. Shown only while there
 * are some.
 */
class UnmatchedEventReferencesWidget extends TableWidget
{
    use HasWidgetShield {
        canView as shieldCanView;
    }

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return static::shieldCanView() && self::hasEvents();
    }

    /**
     * Nothing at all on the dashboard once the last event is sorted out —
     * not even an empty table left behind after fixing it from here.
     */
    public function render(): View
    {
        return self::hasEvents() ? parent::render() : view('filament.widgets.hidden');
    }

    /**
     * The events themselves, not just the cached count: an event deleted by
     * the Outlook sync must not keep an empty widget up for ten minutes.
     */
    private static function hasEvents(): bool
    {
        return UnmatchedEventReferences::count() > 0 && UnmatchedEventReferences::events()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Events with a matter number not in the system'))
            ->description(__('From :days days ago onwards. Add the matter, or correct the event\'s title or linked matters.', ['days' => UnmatchedEventReferences::DAYS_BACK]))
            ->query(fn () => UnmatchedEventReferences::events())
            ->extraAttributes(['class' => 'fi-dashboard-compact-table'])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('start_datetime')
                    ->label(__('Date'))
                    ->formatStateUsing(fn (CalendarEvent $record) => $record->start_datetime?->translatedFormat('D d/m/Y g:i A'))
                    ->badge()
                    ->color(fn (CalendarEvent $record) => $record->start_datetime?->isPast() ? 'gray' : 'warning'),
                TextColumn::make('title')
                    ->label(__('Event'))
                    ->wrap(),
                TextColumn::make('missing_numbers')
                    ->label(__('Matter number not found'))
                    ->state(fn (CalendarEvent $record): array => UnmatchedEventReferences::missing()[$record->id] ?? [])
                    ->badge()
                    ->color('danger'),
            ])
            ->recordActions([
                CalendarMatterActions::linkMatters()
                    ->visible(fn (CalendarEvent $record) => auth()->user()?->can('update', $record)),
                Action::make('newMatter')
                    ->label(__('New matter'))
                    ->icon('heroicon-o-plus')
                    ->iconButton()
                    ->tooltip(__('New matter'))
                    ->url(MatterResource::getUrl('create'))
                    ->openUrlInNewTab()
                    ->visible(fn () => MatterResource::canCreate()),
            ]);
    }
}
