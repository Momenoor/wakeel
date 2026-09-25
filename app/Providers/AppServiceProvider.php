<?php

namespace App\Providers;

use App\Events\NotificationsUpdated;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The language switcher's middleware calls App::setLocale() on every
        // request, which only changes __()/trans() — it never touches Carbon's
        // OWN locale. Filament's ->date()/->dateTime() column helpers format
        // through Carbon::translatedFormat(), which reads Carbon's locale, not
        // the app's, so every date across the panel kept rendering with
        // English month names ("Sep") under an otherwise fully Arabic UI.
        // App::setLocale() dispatches this event on every call, so listening
        // here keeps the two in sync without depending on which middleware or
        // code path changed the locale.
        Event::listen(
            LocaleUpdated::class,
            fn (LocaleUpdated $event) => Carbon::setLocale($event->locale),
        );

        Setting::applyMailConfig();

        // Every database notification — a Laravel Notification or Filament's
        // sendToDatabase() — pings the user's open tabs over Pusher, so the
        // bell and toasts show it at once rather than on the next poll. Once
        // per user per request (named defer), after the response, and never
        // allowed to break whatever sent the notification.
        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel !== 'database'
                || ! $event->notifiable instanceof User
                || ! in_array(config('broadcasting.default'), ['pusher', 'reverb'], true)) {
                return;
            }

            $userId = $event->notifiable->getKey();

            defer(function () use ($userId) {
                try {
                    broadcast(new NotificationsUpdated($userId));
                } catch (Throwable $e) {
                    report($e);
                }
            }, "notifications-updated-{$userId}");
        });
    }
}
