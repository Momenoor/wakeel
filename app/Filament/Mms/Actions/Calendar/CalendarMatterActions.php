<?php

namespace App\Filament\Mms\Actions\Calendar;

use App\Models\CalendarEvent;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use App\Services\MMS\Calendar\OutlookCalendarSync;
use App\Services\MMS\OutlookCalendarService;
use App\Support\MatterSearch;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Linking calendar events to matters — by hand or from their titles — and
 * syncing with the shared Outlook calendar on demand (it also runs every
 * five minutes on its own).
 */
class CalendarMatterActions
{
    /**
     * Pick the event's matters: search by "639/2025", number, court, type
     * or party — or take the ones its title names.
     */
    public static function linkMatters(): Action
    {
        return Action::make('linkMatters')
            ->label(__('Link matters'))
            ->icon('heroicon-o-link')
            ->color('gray')
            ->iconButton()
            ->tooltip(__('Link matters'))
            ->modalHeading(fn (CalendarEvent $record): string => __('Matters for “:title”', ['title' => $record->title]))
            ->fillForm(fn (CalendarEvent $record): array => ['matters' => $record->matters()->pluck('matters.id')->all()])
            ->schema(fn (CalendarEvent $record): array => [
                self::mattersField(fn (): ?string => $record->title),
            ])
            ->action(function (CalendarEvent $record, array $data): void {
                $record->matters()->sync($data['matters'] ?? []);
                app(EventMatterLinker::class)->tidy($record->fresh());

                Notification::make()->title(__('Matters linked'))->success()->send();
            });
    }

    /**
     * The matters picker: searchable, several at once, with a button that
     * adds the matters written in the event's title.
     */
    public static function mattersField(callable $title): Select
    {
        return Select::make('matters')
            ->label(__('Linked matters'))
            ->helperText(__('Search by matter number (639/2025), number, court, type or party name.'))
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => MatterSearch::options($search))
            ->getOptionLabelsUsing(fn (array $values): array => MatterSearch::labels($values))
            ->hintAction(
                Action::make('fromTitle')
                    ->label(__('Find in title'))
                    ->icon('heroicon-o-sparkles')
                    ->action(function (Get $get, Set $set) use ($title): void {
                        $found = MatterReferenceMatcher::matterIds($title($get));
                        $set('matters', array_values(array_unique([...($get('matters') ?? []), ...$found])));

                        Notification::make()
                            ->title($found === [] ? __('No matter number found in the title.') : trans_choice('{1} 1 matter found in the title|[2,*] :count matters found in the title', count($found), ['count' => count($found)]))
                            ->status($found === [] ? 'warning' : 'success')
                            ->send();
                    }),
            );
    }

    public static function linkFromTitlesBulk(): BulkAction
    {
        return BulkAction::make('linkFromTitles')
            ->label(__('Link matters from titles'))
            ->icon('heroicon-o-link')
            ->requiresConfirmation()
            ->modalDescription(__('Adds the matters each selected event\'s title names. Links already made are kept.'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $linker = app(EventMatterLinker::class);
                $links = $records->sum(fn (CalendarEvent $event) => $linker->link($event));

                self::linkedNotification($links);
            });
    }

    /**
     * Every event already in Wakeel — old, current and upcoming.
     */
    public static function linkAllFromTitles(): Action
    {
        return Action::make('linkAllFromTitles')
            ->label(__('Link all events to matters'))
            ->icon('heroicon-o-link')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('Goes through every event — past, current and upcoming — and adds the matters each title names. Links already made are kept.'))
            ->action(function (): void {
                @set_time_limit(300);
                $result = app(EventMatterLinker::class)->linkAll(CalendarEvent::query());

                self::linkedNotification($result['links']);
            });
    }

    /**
     * Brings any stretch of the Outlook calendar in now — for older events;
     * the recent weeks and coming months sync every five minutes anyway.
     */
    public static function syncWithOutlook(): Action
    {
        return Action::make('syncWithOutlook')
            ->label(__('Sync with Outlook'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (): bool => app(OutlookCalendarService::class)->isConfigured())
            ->modalHeading(__('Sync with Outlook'))
            ->modalDescription(__('Adds and updates the Outlook events between these dates and links them to their matters. The coming months also sync every 5 minutes on their own.'))
            ->schema([
                DatePicker::make('from')->label(__('From'))->default(now()->subMonths(3))->required(),
                DatePicker::make('to')->label(__('To'))->default(now()->addMonths(6))->required()->afterOrEqual('from'),
            ])
            ->action(function (array $data): void {
                @set_time_limit(300);

                try {
                    $counts = app(OutlookCalendarSync::class)->sync(Carbon::parse($data['from'])->startOfDay(), Carbon::parse($data['to'])->endOfDay());
                } catch (Throwable $exception) {
                    Notification::make()->title(__('Outlook sync failed'))->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Outlook sync done'))
                    ->body(__(':added added, :updated updated, :removed removed, :linked matter link(s).', $counts))
                    ->success()
                    ->send();
            });
    }

    private static function linkedNotification(int $links): void
    {
        Notification::make()
            ->title(trans_choice('{0} No new matter links found|{1} 1 matter link added|[2,*] :count matter links added', $links, ['count' => $links]))
            ->success()
            ->send();
    }
}
