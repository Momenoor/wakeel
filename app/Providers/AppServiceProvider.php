<?php

namespace App\Providers;

use App\Events\NotificationsUpdated;
use App\Filament\Shared\Users\ImpersonateUserAction;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\Calendar\UnmatchedEventReferences;
use App\Services\Push\WebPushSender;
use App\Support\Currency;
use Carbon\Carbon;
use Carbon\Translator as CarbonTranslator;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Tables\UserActions;

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

        // Arabic day names in full everywhere a short one is asked for
        // ('D' / 'ddd'): «الثلاثاء», not Carbon's «ثلاثاء». Set on both of
        // Carbon's translators: the global one (used after the app switches
        // to Arabic) and the per-locale one (a date's own ->locale('ar')).
        $fullArabicDays = ['weekdays_short' => ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']];
        Carbon::getTranslator()->setMessages('ar', $fullArabicDays);
        CarbonTranslator::get('ar')->setMessages('ar', $fullArabicDays);

        // "Events naming a matter that is not in the system" is kept for ten
        // minutes; any change to an event or a matter starts it afresh.
        foreach ([CalendarEvent::class, Matter::class] as $model) {
            $model::saved(fn () => UnmatchedEventReferences::forget());
            $model::deleted(fn () => UnmatchedEventReferences::forget());
        }

        Setting::applyMailConfig();

        // A sound for each new notification and chat message, with its
        // on/off button beside the notifications bell.
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn (): string => auth()->check() ? view('filament.partials.notification-sound')->render() : '',
        );

        // The font that draws the Dirham sign, on every panel page.
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END, fn (): string => (string) Currency::fontLink());

        // On a phone, typing in a field smaller than 16px makes the iPhone
        // zoom the whole page in (and leave it zoomed): the chat box, the
        // search, every form field. 16px there, as they are elsewhere.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => '<style>@media (max-width: 767px) { input:not([type=checkbox]):not([type=radio]):not([type=range]), textarea, select, [contenteditable=true] { font-size: 16px !important; } }</style>',
        );

        // ->aed(): an amount with the Dirham sign before it, in table columns,
        // detail entries and table totals (instead of money('AED')).
        foreach ([TextColumn::class, TextEntry::class, Summarizer::class] as $component) {
            $component::macro('aed', function (int $decimals = 2) {
                /** @var TextColumn|TextEntry|Summarizer $this */
                return $this
                    ->formatStateUsing(fn ($state) => is_numeric($state) ? Currency::format($state, $decimals) : $state)
                    ->html();
            });
        }

        // Our Impersonate button on the users table, in place of the
        // package's (config/filament-users.php explains why).
        UserActions::register(ImpersonateUserAction::make());

        // Every database notification — a Laravel Notification or Filament's
        // sendToDatabase() — pings the user's open tabs over Pusher, so the
        // bell and toasts show it at once rather than on the next poll. Once
        // per user per request (named defer), after the response, and never
        // allowed to break whatever sent the notification.
        // After every login, the first page offers desktop notifications
        // again if this browser does not have them on.
        Event::listen(Login::class, function (): void {
            if (app()->bound('session')) {
                session()->put('wakeel.prompt_desktop_notifications', true);
            }
        });

        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
                return;
            }

            $userId = $event->notifiable->getKey();

            // And as a Web Push to every browser and phone the user allowed —
            // shown even with no Wakeel tab open.
            if ($event->response instanceof DatabaseNotification
                && PushSubscription::where('user_id', $userId)->exists()) {
                $payload = WebPushSender::payloadFor($event->response);

                defer(function () use ($userId, $payload) {
                    try {
                        app(WebPushSender::class)->sendToUser($userId, $payload);
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            }

            if (! in_array(config('broadcasting.default'), ['pusher', 'reverb'], true)) {
                return;
            }

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
