<?php

namespace App\Services\Notify;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Throwable;

/**
 * One way to tell users something happened: a system notification, which
 * lands in the bell list and — through the NotificationSent listener — shows
 * as a toast, a desktop notification and a Web Push on their devices.
 *
 * Never throws: telling someone about an action must not undo the action.
 */
class UserAlert
{
    /**
     * @param  User|iterable<User|null>|null  $users
     * @param  'info'|'success'|'warning'|'danger'  $status
     */
    public static function send(User|iterable|null $users, string $title, ?string $body = null, ?string $url = null, string $status = 'info'): void
    {
        $recipients = Collection::wrap($users instanceof User ? [$users] : ($users ?? []))
            ->filter(fn ($user) => $user instanceof User)
            ->unique(fn (User $user) => $user->getKey())
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            $notification = Notification::make()->title($title)->status($status);

            if (filled($body)) {
                $notification->body($body);
            }

            if (filled($url)) {
                $notification->actions([
                    Action::make('view')->label(__('View'))->url($url)->markAsRead(),
                ]);
            }

            $notification->sendToDatabase($recipients);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
