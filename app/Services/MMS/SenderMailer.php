<?php

namespace App\Services\MMS;

use App\Models\MailSender;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Microsoft\Graph\Generated\Models\ODataErrors\ODataError;
use RuntimeException;
use Throwable;

/**
 * Sends through one of the department mailboxes instead of the app's own
 * mailer — those kept in the mail_senders table (Settings → Mail senders).
 * For the length of the callback the mailer is that mailbox, then everything
 * is put back. Shared by bulk mail and letters.
 *
 *  - smtp: a cPanel mailbox, logged in with its password;
 *  - microsoft: a Microsoft 365 mailbox, sent through Microsoft Graph with
 *    the app registration in .env (MICROSOFT_GRAPH_*). Microsoft saves the
 *    copy in the mailbox's Sent Items itself.
 */
class SenderMailer
{
    /**
     * The mailboxes someone may send from, for the "Send from" lists: their own first, then the office's shared
     * ones — unless they are kept to their own (Users → Edit). Without a user (the settings' defaults, a job):
     * the shared ones.
     *
     * @return array<string, string> key => display name
     */
    public static function options(?User $user = null, bool $sharedOnly = false): array
    {
        $user ??= $sharedOnly ? null : auth()->user();
        $label = fn (array $sender, string $key): string => ($sender['name'] ?? $key).' <'.($sender['address'] ?? '').'> — '
            .(self::isMicrosoft($sender) ? 'Microsoft 365' : 'cPanel');

        $senders = collect(self::all())->filter(fn (array $sender): bool => $sender['sends']);
        $own = $user ? $senders->filter(fn (array $sender): bool => $sender['owner'] === (int) $user->getKey()) : collect();
        $shared = ($user === null || $user->shared_senders) ? $senders->filter(fn (array $sender): bool => $sender['owner'] === null) : collect();

        return $own->union($shared)->map($label)->all();
    }

    /**
     * The mailboxes read for their emails (MatterReplyCollector): those set to be.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function collected(): array
    {
        return array_filter(self::all(), fn (array $sender): bool => $sender['collects']);
    }

    /** Whose mailbox it is: a user's own (their id), or none (one of the office's shared ones). */
    public static function ownerOf(?string $key): ?int
    {
        return filled($key) ? (self::all()[$key]['owner'] ?? null) : null;
    }

    /**
     * Every active mailbox, shared and personal — read once a request (a change to one forgets it).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (app()->bound(self::CACHE)) {
            return app(self::CACHE);
        }

        try {
            $managed = MailSender::query()->where('is_active', true)->orderBy('name')->get()
                ->mapWithKeys(fn (MailSender $sender) => [$sender->key => $sender->toSender()])
                ->all();
        } catch (Throwable) {
            return []; // Before the table exists (e.g. mid-install).
        }

        app()->instance(self::CACHE, $managed);

        return $managed;
    }

    /** Read again on next ask: a mailbox was added, changed or removed. */
    public static function forget(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    private const CACHE = 'sender-mailer.all';

    /**
     * @return array<string, mixed>
     */
    public static function sender(string $key): array
    {
        $sender = self::all()[$key] ?? null;

        if (! is_array($sender)) {
            throw new RuntimeException("Unknown mail sender [{$key}].");
        }

        return $sender;
    }

    /**
     * An email's body with the signature of the mailbox it goes from, at its end — every email sent from
     * a mailbox (letters, minutes, bulk mail) through here, so none goes without it.
     *
     * @param  array<string, mixed>|null  $sender
     */
    public static function withSignature(string $html, ?array $sender, string $gap = '<br>'): string
    {
        $signature = trim((string) ($sender['signature'] ?? ''));

        return $signature === '' ? $html : $html.$gap.$signature;
    }

    public static function isMicrosoft(array $sender): bool
    {
        return ($sender['driver'] ?? MailSender::SMTP) === MailSender::MICROSOFT;
    }

    /**
     * @param  array<string, mixed>  $sender
     */
    public static function using(array $sender, callable $callback): mixed
    {
        $keys = [
            'mail.default',
            'mail.mailers.smtp.host', 'mail.mailers.smtp.port', 'mail.mailers.smtp.username',
            'mail.mailers.smtp.password', 'mail.mailers.smtp.encryption',
            'mail.from.address', 'mail.from.name',
        ];
        $original = collect($keys)->mapWithKeys(fn ($key) => [$key => config($key)])->all();

        try {
            self::applyAsDefault($sender);

            return $callback();
        } catch (Throwable $e) {
            // Re-thrown: the caller decides what a failed send means (retry,
            // mark the recipient failed…). Swallowing it once let a failed
            // send be recorded as sent. With the real reason: Microsoft's
            // reason lies a level down ("Failed to send email: " and nothing).
            $reason = self::reason($e, $sender);
            Log::error('Sending through mailbox '.($sender['address'] ?? '?').' failed: '.$reason);

            throw $reason === $e->getMessage() ? $e : new RuntimeException($reason, 0, $e);
        } finally {
            config($original);
            self::reset();
        }
    }

    /**
     * Why a send failed, in words: the mail server's or Microsoft 365's own
     * reason — found down the chain when the first says nothing — and, when
     * Microsoft refused, what to set up.
     *
     * @param  array<string, mixed>  $sender
     */
    public static function reason(Throwable $e, array $sender = []): string
    {
        $messages = [];
        $refused = false;

        for ($each = $e; $each !== null; $each = $each->getPrevious()) {
            if ($each instanceof ODataError) {
                $code = (string) $each->getError()?->getCode();
                $messages[] = trim($code.': '.$each->getError()?->getMessage(), ': ');
                $refused = $refused || $each->getResponseStatusCode() === 403 || in_array($code, ['ErrorAccessDenied', 'Authorization_RequestDenied', 'AccessDenied'], true);
            } else {
                $messages[] = trim((string) preg_replace('/^Failed to send email:\s*$/', '', trim($each->getMessage())));
            }
        }

        $reason = collect($messages)->filter()->unique()->implode(' — ') ?: class_basename($e);

        if ($refused) {
            $reason = __('Microsoft 365 refused to send from :address: the app registration needs the Mail.Send application permission, with admin consent.', ['address' => $sender['address'] ?? config('mail.from.address')]).' ('.$reason.')';
        }

        return $reason;
    }

    /**
     * Makes the mailbox the app's mailer — until put back (using()), or
     * for the whole request: Settings → Email Settings can name a sender for the
     * app's own emails (notifications), applied at boot by
     * Setting::applyMailConfig().
     *
     * @param  array<string, mixed>  $sender
     */
    public static function applyAsDefault(array $sender): void
    {
        if (self::isMicrosoft($sender)) {
            config([
                'mail.default' => 'microsoft-graph',
                'mail.from.address' => $sender['address'] ?? null,
                'mail.from.name' => $sender['name'] ?? null,
            ]);
        } else {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $sender['host'] ?? null,
                'mail.mailers.smtp.port' => $sender['port'] ?? null,
                'mail.mailers.smtp.username' => $sender['username'] ?? null,
                'mail.mailers.smtp.password' => $sender['password'] ?? null,
                'mail.mailers.smtp.encryption' => $sender['encryption'] ?? null,
                'mail.from.address' => $sender['address'] ?? null,
                'mail.from.name' => $sender['name'] ?? null,
            ]);
        }

        self::reset();
    }

    /**
     * Mailers are built once and kept: drop them so the next send uses
     * the mailbox just configured. The Graph client in particular sends as
     * the from-address it was created with, whatever the email says.
     */
    private static function reset(): void
    {
        app('mail.manager')->purge('smtp');
        app('mail.manager')->purge('microsoft-graph');
        app()->forgetInstance('mail.microsoft-graph.client');
    }
}
