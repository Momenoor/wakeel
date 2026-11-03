<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Mail\UserNotificationMail;
use App\Models\Attachment;
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
     * With files (a decision's, back to the assistant): each goes in a message of its own on the
     * "Notification with a file" template, in its header, with the title and text — and reaches anyone
     * Meta lets a template reach. Without that template, the notification goes as usual and each file
     * after it as a plain file, which Meta delivers only within 24 hours of their last message to the
     * office's number; one not delivered is logged, and the files are with the email and on the page.
     *
     * @param  Collection<int, User>  $users
     * @param  iterable<Attachment>  $files
     */
    public static function whatsapp(Collection $users, string $title, ?string $body, ?string $url, iterable $files = []): void
    {
        $template = WhatsAppTemplate::default(WhatsAppTemplate::NOTIFICATION);
        $files = collect($files)->filter(fn (Attachment $file): bool => $file->fileExists())->values();
        $withFile = $files->isNotEmpty() ? WhatsAppTemplate::default(WhatsAppTemplate::NOTIFICATION_FILE) : null;

        if ($users->isEmpty() || (! $template && ! $withFile) || ! WhatsAppCloud::configured()) {
            return;
        }

        // Meta refuses a parameter with line breaks, tabs or a long run of spaces.
        $oneLine = fn (?string $text, int $limit): string => Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text))), $limit, '…') ?: '—';

        // Once the answer has gone back: each message is a call to Meta, and whoever approved a leave or filed a
        // request waited a second or more for them all.
        defer(function () use ($users, $template, $withFile, $files, $oneLine, $title, $body, $url): void {
            $cloud = app(WhatsAppCloud::class);
            $media = [];
            // Each file uploaded once, for everyone: its media id and the name it is sent under.
            $uploaded = function (int $index) use (&$media, $files, $cloud): array {
                $file = $files[$index];

                return $media[$index] ??= [
                    'id' => $cloud->upload((string) $file->storage()->get($file->path), (string) ($file->mime ?: $file->storage()->mimeType($file->path) ?: 'application/octet-stream'), (string) $file->name),
                    'filename' => (string) $file->name,
                ];
            };

            foreach ($users as $user) {
                $phone = WhatsAppService::formatWhatsAppNumber($user->phone);

                if ($phone === null) {
                    continue;
                }

                $values = [
                    'user' => $oneLine($user->display_name ?: $user->name, 60),
                    'title' => $oneLine($title, 100),
                    'body' => $oneLine($body, 300),
                    'link' => (string) ($url ?: config('app.url')),
                ];

                try {
                    if ($withFile) {
                        foreach ($files->keys() as $index) {
                            $cloud->sendTemplate($phone, $withFile->meta_name, $withFile->language, $withFile->parameterValues([...$values, 'file' => $oneLine($files[$index]->name, 100)]), $uploaded($index));
                        }

                        continue;
                    }

                    $cloud->sendTemplate($phone, $template->meta_name, $template->language, $template->parameterValues($values));

                    foreach ($files->keys() as $index) {
                        try {
                            $cloud->sendDocument($phone, ...$uploaded($index));
                        } catch (Throwable $e) {
                            Log::warning('A notification\'s file not sent on WhatsApp (sent as a plain file: Meta delivers one within 24 hours of their last message only — a "Notification with a file" template reaches anyone)', ['user' => $user->getKey(), 'file' => $files[$index]->name, 'error' => $e->getMessage()]);
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('Notification not sent on WhatsApp', ['user' => $user->getKey(), 'error' => $e->getMessage()]);
                }
            }
        });
    }
}
