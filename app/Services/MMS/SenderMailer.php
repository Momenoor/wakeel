<?php

namespace App\Services\MMS;

use App\Models\MailSender;
use Illuminate\Support\Facades\Log;
use Microsoft\Graph\Generated\Models\ODataErrors\ODataError;
use RuntimeException;
use Throwable;

/**
 * Sends through one of the department mailboxes instead of the app's own
 * mailer — those added in the app (Mail senders) and those in
 * config/mail_senders.php. For the length of the callback the mailer is
 * that mailbox, then everything is put back. Shared by bulk mail and
 * letters.
 *
 *  - smtp: a cPanel mailbox, logged in with its password;
 *  - microsoft: a Microsoft 365 mailbox, sent through Microsoft Graph with
 *    the app registration in .env (MICROSOFT_GRAPH_*). Microsoft saves the
 *    copy in the mailbox's Sent Items itself.
 */
class SenderMailer
{
    /**
     * @return array<string, string> key => display name
     */
    public static function options(): array
    {
        return collect(self::all())
            ->map(fn (array $sender, string $key) => ($sender['name'] ?? $key).' <'.($sender['address'] ?? '').'> — '
                .(self::isMicrosoft($sender) ? 'Microsoft 365' : 'cPanel'))
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $config = collect(config('mail_senders.senders', []))
            ->map(fn (array $sender) => ['driver' => MailSender::SMTP, ...$sender])
            ->all();

        try {
            $managed = MailSender::query()->where('is_active', true)->orderBy('name')->get()
                ->mapWithKeys(fn (MailSender $sender) => [$sender->key => $sender->toSender()])
                ->all();
        } catch (Throwable) {
            $managed = []; // Before the table exists (e.g. mid-install).
        }

        return [...$managed, ...$config];
    }

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
     * for the whole request: System Settings can name a sender for the
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
