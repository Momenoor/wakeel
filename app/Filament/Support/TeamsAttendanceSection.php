<?php

namespace App\Filament\Support;

use App\Models\CalendarEvent;
use App\Models\MatterMinutes;
use App\Services\MMS\TeamsAttendance;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Who joined a meeting's Teams call, and when each joined and left (TeamsAttendance) — on the minutes being
 * recorded, and on a calendar event: read by itself once the meeting is over, or now.
 */
class TeamsAttendanceSection
{
    /**
     * $meeting: the minutes or calendar event — else the record the section is shown for.
     *
     * @param  (Closure(Get, Set, list<array<string, mixed>>): ?string)|null  $afterRead  what else is done once it is read (the minutes' lines ticked), and said
     * @param  (Closure(Closure(): list<array<string, mixed>>): list<Action>)|null  $actions  more actions, given who joined
     */
    public static function make(MatterMinutes|CalendarEvent|null $meeting = null, ?Closure $afterRead = null, ?Closure $actions = null): Section
    {
        $of = fn (?Model $record): MatterMinutes|CalendarEvent|null => $meeting ?? ($record instanceof MatterMinutes || $record instanceof CalendarEvent ? $record : null);
        // Read once a request — and again once read from Teams in it.
        $fresh = null;
        $current = function (?Model $record = null) use (&$fresh, $of): MatterMinutes|CalendarEvent|null {
            $meeting = $of($record);

            return $meeting?->exists ? $fresh ??= $meeting->newQuery()->whereKey($meeting->getKey())->first(['id', 'attendance', 'attendance_read_at']) : null;
        };
        $people = fn (?Model $record = null): array => (array) ($current($record)?->attendance ?? []);
        $readable = fn (?Model $record): bool => ($found = $of($record)) !== null && $found->exists && TeamsAttendance::readable($found);
        $time = fn (?string $at): string => filled($at) ? Carbon::parse($at)->format('H:i') : '—';

        return Section::make(__('Teams attendance'))
            ->key('teamsAttendance')
            ->icon(Heroicon::VideoCamera)
            ->description(__('Who joined the Teams meeting, and when each joined and left — read by itself once the meeting is over.'))
            ->visible(fn (?Model $record): bool => $readable($record) || $people($record) !== [])
            ->collapsible()
            ->headerActions([
                Action::make('readTeamsAttendance')
                    ->label(__('Read the attendance now'))
                    ->icon(Heroicon::ArrowPath)
                    ->link()
                    ->visible(fn (?Model $record): bool => $readable($record))
                    ->action(function (Get $get, Set $set, ?Model $record) use ($of, $people, $afterRead, &$fresh): void {
                        try {
                            $count = app(TeamsAttendance::class)->read($of($record));
                            $fresh = null;
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('The attendance was not read'))->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        if ($count === null) {
                            Notification::make()->warning()->title(__('Teams has no attendance of this meeting yet: it is there once the meeting is over.'))->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__(':count joined the meeting.', ['count' => $count]))
                            ->body($afterRead ? $afterRead($get, $set, $people($record)) : null)
                            ->send();
                    }),
                ...($actions ? $actions($people) : []),
            ])
            ->schema([
                EntryTable::make('teams_attendance', [
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('email')->label(__('Email'))->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('joined_at')->label(__('Joined at'))->formatStateUsing(fn ($state): string => $time($state)),
                    TextEntry::make('left_at')->label(__('Left at'))->formatStateUsing(fn ($state): string => $time($state)),
                    // Each time in the call, when they dropped and joined again.
                    TextEntry::make('intervals')
                        ->label(__('Times in the meeting'))
                        ->state(fn (TextEntry $component): string => collect(EntryTable::row($component)['intervals'] ?? [])
                            ->map(fn (array $interval): string => $time($interval[0] ?? null).'–'.$time($interval[1] ?? null))
                            ->implode(' · ')),
                    TextEntry::make('seconds')->label(__('Minutes attended'))->formatStateUsing(fn ($state): string => (string) (int) round((int) $state / 60)),
                ])->key('table')->state(fn (?Model $record): array => $people($record)),
                Text::make(fn (?Model $record): string => ($at = $current($record)?->attendance_read_at)
                    ? __('Last read :time.', ['time' => $at->diffForHumans()])
                    : __('Not read yet.'))
                    ->color('gray')
                    ->size(TextSize::ExtraSmall),
            ]);
    }
}
