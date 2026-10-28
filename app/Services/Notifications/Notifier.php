<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Mail\UserNotificationMail;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppService;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Tells users of something, each by the channels they want it by (NotificationPreferences): the bell
 * (with its pop-up and the phone push, which follow it), an email, a WhatsApp message. The same title,
 * text and link go by each; a type with an email or message of its own, richer than this, keeps it and
 * only asks who wants it (NotificationPreferences::wanting()).
 *
 * The bell notification records its type, so what is later done with it (the push) knows what it is.
 */
class Notifier
{
    /**
     * @param  iterable<User>  $users  who it is for — whether they want it is asked here
     * @param  Closure(Notification): mixed|null  $configure  more for the bell notification (its icon, actions …)
     * @param  list<NotificationChannel>|null  $only  just these channels (the type may also have an email of its own, sent elsewhere)
     */
    public static function send(NotificationType $type, iterable $users, string $title, ?string $body = null, ?string $url = null, ?Closure $configure = null, ?array $only = null): void
    {
        $channels = fn (NotificationChannel $channel): Collection => in_array($channel, $only ?? NotificationChannel::cases(), true)
            ? NotificationPreferences::wanting($type, $users, $channel)
            : collect();

        self::bell($type, $channels(NotificationChannel::BELL), $title, $body, $url, $configure);
        self::mail($channels(NotificationChannel::MAIL), $title, $body, $url);
        self::whatsapp($channels(NotificationChannel::WHATSAPP), $title, $body, $url);
    }

    /** @param  Collection<int, User>  $users */
    public static function bell(NotificationType $type, Collection $users, string $title, ?string $body, ?string $url, ?Closure $configure = null): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-bell')
            ->iconColor('info')
            ->viewData([NotificationType::DATA_KEY => $type->value]);

        if (filled($url)) {
            // "View" left untranslated: shown in each reader's own language (AppServiceProvider).
            $notification->actions([Action::make('view')->label('View')->translateLabel(false)->url($url)->markAsRead()]);
        }

        if ($configure) {
            $configure($notification);
        }

        $notification->sendToDatabase($users);
    }

    /** @param  Collection<int, User>  $users */
    public static function mail(Collection $users, string $title, ?string $body, ?string $url): void
    {
        foreach ($users->filter(fn (User $user): bool => filled($user->email)) as $user) {
            try {
                Mail::to($user->email)->queue(new UserNotificationMail($user, $title, $body, $url));
            } catch (Throwable $e) {
                Log::warning('Notification email not sent', ['user' => $user->getKey(), 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * One approved template for all of them (Templates → WhatsApp templates, "Notification"): its
     * parameters may use {{user}}, {{title}}, {{body}} and {{link}}. Nothing when there is none, or
     * WhatsApp is not set up, or the user has no number.
     *
     * @param  Collection<int, User>  $users
     */
    public static function whatsapp(Collection $users, string $title, ?string $body, ?string $url): void
    {
        $template = WhatsAppTemplate::default(WhatsAppTemplate::NOTIFICATION);

        if ($users->isEmpty() || ! $template || ! WhatsAppCloud::configured()) {
            return;
        }

        // Meta refuses a parameter with line breaks, tabs or a long run of spaces.
        $oneLine = fn (?string $text, int $limit): string => Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text))), $limit, '…') ?: '—';

        // Once the answer has gone back: each message is a call to Meta, and whoever approved a leave or filed a
        // request waited a second or more for them all.
        defer(function () use ($users, $template, $oneLine, $title, $body, $url): void {
            foreach ($users as $user) {
                $phone = WhatsAppService::formatWhatsAppNumber($user->phone);

                if ($phone === null) {
                    continue;
                }

                try {
                    app(WhatsAppCloud::class)->sendTemplate($phone, $template->meta_name, $template->language, $template->parameterValues([
                        'user' => $oneLine($user->display_name ?: $user->name, 60),
                        'title' => $oneLine($title, 100),
                        'body' => $oneLine($body, 300),
                        'link' => (string) ($url ?: config('app.url')),
                    ]));
                } catch (Throwable $e) {
                    Log::warning('Notification not sent on WhatsApp', ['user' => $user->getKey(), 'error' => $e->getMessage()]);
                }
            }
        });
    }
}
