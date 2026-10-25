<?php

namespace App\Services\MMS;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Filament\Mms\Pages\UnfiledEmails;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\EmailForward;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The filing mailbox (Mail senders → Filing mailbox): an email whose matter could not be told, read by someone in
 * the office and forwarded here naming its matter (1200/2026 in the subject or first line, or in the address:
 * name+1200-2026@…), is filed on that matter as it came from its own sender — not as the forward. One already
 * waiting in Unfiled emails is filed itself (and so leaves that list); any other is kept from the forward
 * (ForwardedEmail). Naming more than one matter, it waits in Unfiled emails as any other whose matter is not
 * certain, those matters' people asked which. Only from a user, sent by them (not someone using their address),
 * who may work on the matter named; they are told in the bell what came of it.
 */
class ForwardedEmailFiler
{
    public const FILED = 'filed';

    public const FILED_WAITING = 'filed_waiting';

    public const NOT_A_USER = 'not_a_user';

    public const UNVERIFIED = 'unverified';

    public const NO_MATTER = 'no_matter';

    /** It names more than one matter: waiting in Unfiled emails, those matters' people asked which. */
    public const HELD = 'held';

    public const NOT_ALLOWED = 'not_allowed';

    public const NO_ORIGINAL = 'no_original';

    public const ALREADY_FILED = 'already_filed';

    public const FAILED = 'failed';

    /** Being read: a run has it. */
    public const READING = 'reading';

    /** How far apart the time quoted in a forward and the one an email was kept with may be, and still be one email. */
    private const SAME_EMAIL_MINUTES = 15;

    /** Still being read this long after: the run that had it died — another reads it again. */
    private const STUCK_MINUTES = 30;

    /**
     * A forward read in the filing mailbox, once: what came of it — null when it was read before.
     *
     * @param  array<string, mixed>  $message  as the collector reads one
     */
    public function handle(array $message, string $id, string $senderKey, MatterReplyCollector $collector): ?string
    {
        try {
            // Read once, whichever run reaches it first.
            $forward = EmailForward::create(['sender_key' => $senderKey, 'message_id' => mb_substr($id, 0, 191), 'outcome' => self::READING, 'subject' => mb_substr((string) ($message['subject'] ?? ''), 0, 500)]);
        } catch (UniqueConstraintViolationException) {
            // Read before — unless the run reading it died (still reading long after): this run takes it over.
            $stuck = EmailForward::query()->where('sender_key', $senderKey)->where('message_id', mb_substr($id, 0, 191))
                ->where('outcome', self::READING)->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES));
            if ((clone $stuck)->update(['updated_at' => now()]) === 0) {
                return null;
            }
            $forward = EmailForward::query()->where('sender_key', $senderKey)->where('message_id', mb_substr($id, 0, 191))->sole();
        }

        $user = self::userOf((string) ($message['from'] ?? ''));

        try {
            $outcome = $this->outcome($message, $senderKey, $user, $forward, $collector);
        } catch (Throwable $e) {
            // Not filed (Microsoft 365, the disk …): said in the log, and to the forwarder.
            Log::warning('Forwarded email not filed: '.$e->getMessage(), ['forward' => $id]);
            $outcome = self::FAILED;
        }
        $forward->update(['outcome' => $outcome, 'user_id' => $user?->getKey()]);

        if ($user !== null && ! in_array($outcome, [self::FILED, self::FILED_WAITING], true)) {
            $this->tell($user, $outcome, $forward);
        }

        return $outcome;
    }

    private function outcome(array $message, string $senderKey, ?User $user, EmailForward $forward, MatterReplyCollector $collector): string
    {
        // Strangers' emails here are nobody's instruction.
        if ($user === null) {
            return self::NOT_A_USER;
        }

        if (! self::sentByThem($message, SenderMailer::isMicrosoft(SenderMailer::all()[$senderKey] ?? []))) {
            return self::UNVERIFIED;
        }

        $original = ForwardedEmail::original($message);
        $ids = ForwardedEmail::matterIds($message, (string) (SenderMailer::all()[$senderKey]['address'] ?? ''), $original);

        $matters = Matter::query()->whereKey($ids)->get();
        $matter = $matters->first();
        $forward->update(['matter_id' => $matter?->getKey()]);

        if ($matter === null) {
            return self::NO_MATTER;
        }

        // On none of the matters named may they work.
        if ($matters->doesntContain(fn (Matter $named): bool => $user->can('update', $named))) {
            return self::NOT_ALLOWED;
        }

        if ($original === null) {
            return self::NO_ORIGINAL;
        }

        // A time quoted that cannot be read: when the forward came.
        $original['at'] ??= $message['at'] ?? null;

        // Kept already: on a matter — or waiting in Unfiled emails, filed itself (so it leaves the list).
        $kept = self::kept($original);

        if ($kept?->status === MatterEmail::FILED) {
            $forward->update(['matter_email_id' => $kept->getKey(), 'matter_id' => $kept->matter_id]);

            return self::ALREADY_FILED;
        }

        // Set aside (its files gone): kept again from the forward.
        if ($kept?->status === MatterEmail::DISMISSED) {
            $kept->delete();
            $kept = null;
        }

        // A reply to a bulk mail of no matter (no status): left as it is, for its campaign — kept anew on the matter.
        if ($kept !== null && $kept->status !== MatterEmail::PENDING) {
            $kept = null;
        }

        // More than one matter named: in Unfiled emails as any other whose matter is not certain — the one
        // waiting there already asked about these matters instead.
        if ($matters->count() > 1) {
            $candidates = $matters->modelKeys();
            $email = $kept !== null ? $collector->askAbout($kept, $candidates) : $collector->holdForwarded($original, $senderKey, $candidates, $forward->message_id);
            $forward->update(['matter_email_id' => $email->getKey()]);

            return self::HELD;
        }

        if ($kept !== null) {
            $collector->file($kept, $matter, (int) $user->getKey());
            $forward->update(['matter_email_id' => $kept->getKey()]);

            return self::FILED_WAITING;
        }

        $email = $collector->keepForwarded($matter, $original, $senderKey, (int) $user->getKey(), $forward->message_id);
        $forward->update(['matter_email_id' => $email->getKey()]);

        return self::FILED;
    }

    /** The user an address is: their sign-in email, or their Microsoft 365 account. */
    public static function userOf(string $address): ?User
    {
        $address = mb_strtolower(trim($address));

        return $address === '' ? null : User::query()
            ->whereRaw('lower(email) = ?', [$address])
            ->orWhereRaw('lower(ms_account) = ?', [$address])
            ->first();
    }

    /**
     * Whether the forward was sent by the person whose address it bears, not by someone using it — read only from
     * what the receiving server wrote, never from what the sender could:
     *  - a Microsoft 365 filing mailbox: sent from inside the office (Exchange strips its own X-MS-Exchange-
     *    Organization headers from any email that comes from outside, so one there is its own);
     *  - the topmost Authentication-Results (the receiving server's, above any the sender wrote): DMARC passed for
     *    the address's own domain, or DKIM or SPF passed for that domain (not merely for some domain);
     *  - a cPanel filing mailbox: sent through the server signed in as them (the topmost header the server adds).
     *
     * @param  array<string, mixed>  $message
     */
    public static function sentByThem(array $message, bool $microsoft = false): bool
    {
        $headers = ($message['headers'] ?? null) instanceof Closure ? ($message['headers'])() : (array) ($message['headers'] ?? []);
        $headers = array_change_key_case($headers);
        // A header given more than once: its topmost.
        $top = fn (string $name): string => trim(strtok((string) ($headers[$name] ?? ''), "\n") ?: '');
        $from = mb_strtolower(trim((string) ($message['from'] ?? '')));
        $domain = str_contains($from, '@') ? substr($from, strrpos($from, '@') + 1) : '';

        if ($from === '' || $domain === '') {
            return false;
        }

        if ($microsoft && strcasecmp($top('x-ms-exchange-organization-authas'), 'Internal') === 0) {
            return true;
        }

        $results = mb_strtolower($top('authentication-results'));
        if (! preg_match('/\bdmarc=fail\b/', $results)) {
            // The domain each check passed for, as it names it.
            $passed = fn (string $check, string $property): array => preg_match_all('/\b'.$check.'=pass\b[^;]*?\b'.preg_quote($property, '/').'=([^\s;]+)/', $results, $m) ? $m[1] : [];
            $ours = fn (string $value): bool => self::sameDomain(ltrim(str_contains($value, '@') ? substr($value, strrpos($value, '@') + 1) : $value, '@'), $domain);

            foreach ([['dmarc', 'header.from'], ['dkim', 'header.d'], ['dkim', 'header.i'], ['spf', 'smtp.mailfrom']] as [$check, $property]) {
                if (collect($passed($check, $property))->contains($ours)) {
                    return true;
                }
            }
        }

        // cPanel: sent through the server signed in as that address.
        if (! $microsoft) {
            foreach (['x-authenticated-sender', 'x-get-message-sender-via'] as $header) {
                if (str_contains(mb_strtolower($top($header)), $from)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** One domain, or one a subdomain of the other (mail.jpa.ae and jpa.ae) — DMARC's relaxed alignment. */
    private static function sameDomain(string $a, string $b): bool
    {
        $a = trim(mb_strtolower($a), '. ');
        $b = trim(mb_strtolower($b), '. ');

        return $a !== '' && ($a === $b || str_ends_with($a, '.'.$b) || str_ends_with($b, '.'.$a));
    }

    /**
     * The email already kept, by its Message-ID — or, quoted in a forward, by its sender, subject and time.
     *
     * @param  array<string, mixed>  $original
     */
    private static function kept(array $original): ?MatterEmail
    {
        $received = MatterEmail::query()->where('direction', MatterEmail::RECEIVED);

        if (filled($id = MatterEmail::messageId($original['message_id'] ?? null))) {
            return (clone $received)->where('message_id', $id)->latest('id')->first();
        }

        $from = (string) ($original['from'] ?? '');
        $subject = trim((string) ($original['subject'] ?? ''));

        if ($from === '' || $subject === '') {
            return null;
        }

        $candidates = (clone $received)
            ->where('status', MatterEmail::PENDING)
            ->where('subject', $subject)
            ->whereRaw('lower(`from`) like ?', ['%'.mb_strtolower($from).'%'])
            ->when(filled($original['at'] ?? null), function ($query) use ($original) {
                $at = Carbon::parse($original['at'])->setTimezone(config('app.timezone'));

                $query->whereBetween('at', [$at->copy()->subMinutes(self::SAME_EMAIL_MINUTES), $at->copy()->addMinutes(self::SAME_EMAIL_MINUTES)]);
            })
            ->get();

        // Only when it can be only one.
        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /** Not filed (or filed before): the forwarder told why, in the bell. */
    private function tell(User $user, string $outcome, EmailForward $forward): void
    {
        $matter = $forward->matter;
        $reference = $matter ? $matter->number.'/'.$matter->year : null;

        $title = match ($outcome) {
            self::UNVERIFIED => __('The email you forwarded to be filed was not filed: it could not be confirmed that it was sent by you.'),
            self::NO_MATTER => __('The email you forwarded to be filed was not filed: no matter in the system was found in its subject, first line or address.'),
            self::HELD => __('The email you forwarded names more than one matter: it is waiting in Unfiled emails for its matter to be chosen.'),
            self::NOT_ALLOWED => __('The email you forwarded to be filed was not filed: you may not work on matter :matter.', ['matter' => $reference]),
            self::NO_ORIGINAL => __('The email you forwarded to be filed was not filed: the email in it could not be read. Forward it as an attachment instead.'),
            self::ALREADY_FILED => __('The email you forwarded is already filed on matter :matter.', ['matter' => $reference]),
            self::FAILED => __('The email you forwarded to be filed could not be filed just now. Forward it again in a while.'),
            default => __('The email you forwarded to be filed was not filed.'),
        };
        $hint = $outcome === self::NO_MATTER
            ? __('Write the matter number (e.g. 1200/2026) in the subject or the first line, or send it to the filing address with +1200-2026 before the @.')
            : null;
        $url = match (true) {
            $matter !== null && $outcome === self::ALREADY_FILED => MatterResource::getUrl('view', ['record' => $matter], panel: 'mms'),
            // Read in the scheduled run: the forwarder's own permission, not the signed-in user's.
            $outcome === self::HELD && $user->can('View:UnfiledEmails') => UnfiledEmails::getUrl(panel: 'mms'),
            default => null,
        };

        try {
            // In the bell, as they chose for this kind (Profile → My notifications).
            Notifier::send(NotificationType::MATTER_EMAIL_FORWARDED, [$user], $title, null, $url, fn (Notification $notification) => $notification
                ->{in_array($outcome, [self::ALREADY_FILED, self::HELD], true) ? 'info' : 'warning'}()
                ->icon('heroicon-o-arrow-uturn-right')
                ->body(MatterReplyCollector::bullets([trim((string) $forward->subject), $hint]))
                ->actions($url ? [Action::make('view')->label(__('View'))->url($url)->markAsRead()] : []), only: [NotificationChannel::BELL]);
        } catch (Throwable $e) {
            Log::info('Forwarded email notification not sent: '.$e->getMessage());
        }
    }
}
