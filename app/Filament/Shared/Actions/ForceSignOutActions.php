<?php

namespace App\Filament\Shared\Actions;

use App\Models\User;
use App\Services\Auth\ForceSignOut;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Tables\UserActions;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Tables\UserBulkActions;

/**
 * "Sign out" on the Users table (the tomatophp/filament-users resource),
 * for one user or a selection — see ForceSignOut.
 */
class ForceSignOutActions
{
    private static bool $registered = false;

    /**
     * Added through the plugin's own hooks, once — they append to a static
     * list, so a second call would show the action twice.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        UserActions::register(self::action());
        UserBulkActions::register(self::bulkAction());
    }

    public static function action(): Action
    {
        return Action::make('forceSignOut')
            ->label(__('Sign out'))
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('warning')
            ->iconButton()
            ->tooltip(__('Sign out'))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Sign out :name?', ['name' => $record->name]))
            ->modalDescription(__('They are signed out of Wakeel on every browser and device, and must log in again.'))
            ->modalSubmitActionLabel(__('Sign out'))
            // Not your own row: that would sign you out on your next click.
            ->visible(fn (User $record): bool => $record->getKey() !== auth()->id() && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (User $record): void {
                ForceSignOut::users([$record]);

                self::done(1);
            });
    }

    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('forceSignOut')
            ->label(__('Sign out selected users'))
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Sign out the selected users?'))
            ->modalDescription(__('They are signed out of Wakeel on every browser and device, and must log in again. You are never signed out yourself.'))
            ->modalSubmitActionLabel(__('Sign out'))
            ->visible(fn (): bool => auth()->user()?->can('update', new User) ?? false)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $result = ForceSignOut::users($records->filter(fn ($user) => auth()->user()?->can('update', $user)));

                self::done($result['users']);
            });
    }

    private static function done(int $count): void
    {
        $notification = Notification::make()
            ->title(trans_choice('{0} No one was signed out|{1} 1 user signed out|[2,*] :count users signed out', $count, ['count' => $count]))
            ->success();

        if (! ForceSignOut::usesDatabaseSessions()) {
            $notification->warning()->body(__('Sessions are not kept in the database (SESSION_DRIVER), so already open sessions stay until they expire.'));
        }

        $notification->send();
    }
}
