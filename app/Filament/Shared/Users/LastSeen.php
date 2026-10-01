<?php

namespace App\Filament\Shared\Users;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Schemas\UserInfolist;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Tables\UserFilters;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Tables\UsersTable;

/**
 * When each user was last in Wakeel (last_seen_at, stamped by
 * TrackUserLastSeen): on the Users table — "Online" or how long ago, the
 * exact time on hover, sortable, with an "Online now" filter — and on a
 * user's page.
 *
 * Labels are closures: the users plugin builds these once, at boot, and
 * they must follow the language of each request.
 */
class LastSeen
{
    private static bool $registered = false;

    public static function register(): void
    {
        // The plugin keeps them in static lists, and the app boots again
        // (each test does): once only.
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        UsersTable::register(self::column());
        UserFilters::register(self::filter());
        UserInfolist::register(self::entry());
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('last_seen_at')
            ->label(fn () => __('Last seen'))
            ->formatStateUsing(fn (User $record): string => $record->isOnline() ? __('Online') : (string) $record->last_seen_at?->diffForHumans())
            ->placeholder(fn () => __('Never seen'))
            ->badge()
            ->color(fn (User $record): string => $record->isOnline() ? 'success' : 'gray')
            ->tooltip(fn (User $record): ?string => $record->last_seen_at?->format('d/m/Y H:i'))
            ->sortable();
    }

    public static function filter(): Filter
    {
        return Filter::make('online')
            ->label(fn () => __('Online now'))
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->where('last_seen_at', '>', now()->subSeconds(User::ONLINE_WITHIN_SECONDS)));
    }

    public static function entry(): TextEntry
    {
        return TextEntry::make('last_seen_at')
            ->label(fn () => __('Last seen'))
            ->state(fn (User $record): string => $record->lastSeenText())
            ->badge()
            ->color(fn (User $record): string => $record->isOnline() ? 'success' : 'gray')
            ->tooltip(fn (User $record): ?string => $record->last_seen_at?->format('d/m/Y H:i'));
    }
}
