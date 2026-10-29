<?php

namespace App\Services\MMS;

use App\Models\MailboxFolderForward;
use App\Models\MailboxForwardedEmail;
use App\Models\MailSender;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Mailbox folders (its own module, the super administrator's): each folder of a mailbox read over IMAP forwarded
 * on to an address of its own — what a mailbox whose filters can only move an email (Yahoo) cannot do by itself.
 * A filter there moves the emails it picks into a folder; every email that comes into it is sent on from the
 * mailbox, once, its original attached as it came (.eml) — answered, the answer goes to its own sender.
 */
class MailboxFolderForwarder
{
    /** The module's flag (config/modules.php). */
    public const MODULE = 'mms_mailbox_folders';

    /** At most so many of a folder's emails a run: a mailbox's server limits how many it sends an hour. */
    public const PER_RUN = 20;

    /** Tried so many times, an email is passed over — not to hold up the folder's others. */
    public const ATTEMPTS = 3;

    public function __construct(private readonly SentFolder $mailboxes) {}

    public static function enabled(): bool
    {
        return (bool) config('modules.'.self::MODULE, true);
    }

    /**
     * Every folder forwarded on: its emails that came since the last run sent on. How many went.
     */
    public function run(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $sent = 0;
        $forwards = MailboxFolderForward::query()->where('is_active', true)
            ->whereHas('mailSender', fn ($query) => $query->where('is_active', true)->where('driver', MailSender::SMTP))
            ->with('mailSender')->get();

        foreach ($forwards as $forward) {
            try {
                $sent += $this->forwardNew($forward);
            } catch (Throwable $e) {
                // The folder could not be read (gone, the password changed): said on the page, tried again next run.
                Log::warning('Mailbox folder not forwarded: '.$forward->mailSender->address.' / '.$forward->folder, ['error' => $e->getMessage()]);
                $forward->update(['error' => Str::limit($e->getMessage(), 1000)]);
            }
        }

        return $sent;
    }

    /**
     * A folder's emails after the last one gone on, sent on — the oldest first; one that fails stops the run there,
     * to be tried again, until it has been tried ATTEMPTS times. How many went.
     */
    public function forwardNew(MailboxFolderForward $forward, int $limit = self::PER_RUN): int
    {
        $mailbox = $forward->mailSender;
        $sent = 0;

        foreach ($this->mailboxes->emails($mailbox->key, $forward->folder, (int) $forward->last_uid, $limit) as $email) {
            $log = $this->log($mailbox, $forward->folder, $email, $forward->forward_to);

            if ($log->forwarded_at === null) {
                try {
                    $this->send($mailbox, $email, $forward->forward_to);
                    $log->fill(['forwarded_at' => now(), 'error' => null]);
                    $sent++;
                } catch (Throwable $e) {
                    $log->fill(['error' => Str::limit($e->getMessage(), 1000)]);
                }

                $log->attempts++;
                $log->save();

                if ($log->forwarded_at === null && $log->attempts < self::ATTEMPTS) {
                    $forward->update(['error' => $log->error]);

                    return $sent;
                }
            }

            $forward->update(['last_uid' => max((int) $forward->last_uid, (int) $email['uid']), 'error' => null]);
        }

        return $sent;
    }

    /**
     * One email of a folder sent on to whom it is asked — again if it went before. Throws when it can't be.
     */
    public function forwardOne(MailSender $mailbox, string $folder, int $uid, string $to): void
    {
        $email = collect($this->mailboxes->emails($mailbox->key, $folder, $uid - 1, 1))->firstWhere('uid', $uid)
            ?? throw new RuntimeException(__('The email is no longer in the folder.'));
        $log = $this->log($mailbox, $folder, $email, $to);
        $log->attempts++;

        try {
            $this->send($mailbox, $email, $to);
            $log->fill(['forwarded_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            $log->fill(['error' => Str::limit($e->getMessage(), 1000)])->save();

            throw $e;
        }
    }

    /**
     * The mailbox's folders to choose from — path => name shown (the Inbox by its own name).
     *
     * @return array<string, string>
     */
    public function folders(MailSender $mailbox): array
    {
        return collect($this->mailboxes->folders($mailbox->key))
            ->mapWithKeys(fn (string $folder): array => [$folder => strcasecmp($folder, 'INBOX') === 0 ? __('Inbox') : $folder])
            ->all();
    }

    /**
     * Where a folder's forwarding starts: after the email newest in it now — those already there are not sent on.
     */
    public function lastUid(MailSender $mailbox, string $folder): int
    {
        return (int) ($this->latest($mailbox, $folder, 1)[0]['uid'] ?? 0);
    }

    /**
     * A folder's newest emails, the newest first — their headers alone, for showing.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(MailSender $mailbox, string $folder, int $limit): array
    {
        return $this->mailboxes->emails($mailbox->key, $folder, 0, $limit, newestFirst: true, whole: false);
    }

    /**
     * The emails of a folder forwarded already, by Message-ID — the last time each went, to whom.
     *
     * @param  list<string>  $messageIds
     * @return array<string, MailboxForwardedEmail>
     */
    public static function forwarded(MailSender $mailbox, string $folder, array $messageIds): array
    {
        return MailboxForwardedEmail::query()->where('mail_sender_id', $mailbox->getKey())->where('folder', $folder)
            ->whereIn('message_id', $messageIds)->orderBy('updated_at')->get()
            ->keyBy('message_id')
            ->all();
    }

    /**
     * An email sent on from the mailbox: "Fwd:" and its subject, who sent it, when and to whom above its text,
     * the original attached as it came (.eml) — answered, the answer goes to its sender.
     *
     * @param  array<string, mixed>  $email  as SentFolder::emails() reads one
     */
    public function send(MailSender $mailbox, array $email, string $to): void
    {
        $subject = trim((string) $email['subject']);

        SenderMailer::using($mailbox->toSender(), fn () => Mail::html(self::body($email), function (Message $mail) use ($email, $to, $subject): void {
            $mail->to($to)->subject('Fwd: '.($subject !== '' ? $subject : '(no subject)'));

            if (filter_var($email['from'], FILTER_VALIDATE_EMAIL)) {
                $mail->replyTo($email['from'], $email['from_name'] ?: null);
            }

            if (($email['raw'] ?? '') !== '') {
                $name = MatterOneDriveExplorer::cleanName(Str::limit($subject !== '' ? $subject : 'email', SentEmailArchive::NAME_LIMIT, '')).'.eml';
                $mail->attachData($email['raw'], $name, ['mime' => 'message/rfc822']);
            }
        }));
    }

    /**
     * A forwarded email's text: the original's header as a mail program writes it, then the original's text.
     *
     * @param  array<string, mixed>  $email
     */
    public static function body(array $email): string
    {
        $from = trim($email['from_name'] !== '' ? $email['from_name'].' <'.$email['from'].'>' : (string) $email['from']);
        $lines = array_filter([
            'From' => $from,
            'Date' => $email['at'] ? Carbon::parse($email['at'])->setTimezone(config('app.timezone'))->format('D, j M Y \a\t H:i') : '',
            'Subject' => (string) $email['subject'],
            'To' => implode(', ', (array) $email['to']),
            'Cc' => implode(', ', (array) $email['cc']),
        ], fn (string $value): bool => $value !== '');

        $header = collect($lines)->map(fn (string $value, string $name): string => $name.': '.e($value))->implode('<br>');

        return '<div>---------- Forwarded message ---------<br>'.$header.'</div><br>'.$email['html'];
    }

    /**
     * How an email is known among those forwarded: its Message-ID — one without, its place in the folder.
     *
     * @param  array<string, mixed>  $email
     */
    public static function messageKey(array $email): string
    {
        return mb_substr((string) $email['message_id'] !== '' ? (string) $email['message_id'] : 'uid-'.$email['uid'], 0, 191);
    }

    /**
     * @param  array<string, mixed>  $email
     */
    private function log(MailSender $mailbox, string $folder, array $email, string $to): MailboxForwardedEmail
    {
        return MailboxForwardedEmail::query()->firstOrNew([
            'mail_sender_id' => $mailbox->getKey(),
            'folder' => $folder,
            'message_id' => self::messageKey($email),
            'forwarded_to' => $to,
        ], [
            'subject' => mb_substr((string) $email['subject'], 0, 500),
            'from' => mb_substr((string) $email['from'], 0, 255),
            'at' => $email['at'],
        ]);
    }
}
