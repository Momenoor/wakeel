<?php

namespace App\Services\MMS;

use App\Enums\NotificationType;
use App\Filament\Mms\Pages\UnfiledEmails;
use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Attachment;
use App\Models\BulkMailRecipient;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\MatterProgress;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Support\Dates;
use App\Support\Documents;
use App\Support\Tolerant;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment as ImapAttachment;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Replies to a matter's emails, collected from the mailbox each went from
 * (over IMAP for a cPanel mailbox — its Inbox, or the folder set; Microsoft
 * Graph for a Microsoft 365 one — every folder but what it sent or is
 * writing, junk and the bin — which needs the Mail.Read application permission):
 * each kept as a PDF, with every file it came with, with the matter's
 * attachments and in its OneDrive replies folder; a step in its progress;
 * whoever sent the email told.
 *
 * A reply is known by the email it answers (In-Reply-To / References) —
 * or, as Microsoft 365 gives sent mail its own id, by its subject ("RE: …")
 * coming from someone the email went to. Each reply is kept once.
 *
 * Any other email from a matter's party is kept on its matter the same way
 * — when IncomingEmailMatcher is certain which; when not, it waits on the
 * Unfiled emails page for a person to choose (file()) or set aside.
 *
 * An email from one of the office's own addresses is never a party's: a copy
 * of what Wakeel sent is read and left; one written from Outlook to someone
 * outside the office is kept on its matter as sent (handleOwn()).
 */
class MatterReplyCollector
{
    /** How far back sent emails are watched for replies. */
    private const WATCH_DAYS = 90;

    /** In a bell notification about an email: which email (MatterEmail id). */
    public const EMAIL_KEY = 'matter_email';

    /** Setting: how many days back a mailbox is ever read — the first time, for one matter, or after a long gap. */
    public const LOOK_BACK = 'mail_look_back_days';

    /** The most it may be: each run still reads only a short slice, but further back than this is a backlog no run should take on. */
    public const MAX_LOOK_BACK = 30;

    /** Days back a mailbox is read (1 unless set). */
    public static function lookBackDays(): int
    {
        return max(1, min(self::MAX_LOOK_BACK, (int) Setting::get(self::LOOK_BACK, 1)));
    }

    /** What the last automatic run did: when it started and finished, what it kept, whether it stopped for time, its errors. */
    public const LAST_RUN = 'mail_collect_last_run';

    /** Minutes between automatic runs (sooner while a backlog is left). */
    private const EVERY_MINUTES = 10;

    /** The names Outlook gives the pictures of an email's text: image001.png, Outlook-ab12cd.png (isPartOfText()). */
    private const OUTLOOK_PICTURE = '/^(?:image\d{3}|Outlook-[\w-]+)\.(?:png|jpe?g|gif|bmp)$/i';

    /** No bigger than a signature's picture: one Outlook named, larger, is a picture pasted in, kept. */
    private const SIGNATURE_PICTURE_BYTES = 100 * 1024;

    /** How far apart one email's copies may arrive in two of our mailboxes (twinKept()). */
    private const TWIN_MINUTES = 5;

    /** @var array<int, array{matter: int, label: string, url: string, downloads: list<array{name: string, url: string}>}> matters whose emails were kept in the system only (they have no OneDrive folder), with the files to download and put there by hand */
    private array $unlinked = [];

    /** The original email (.eml) is kept when it is no bigger than this. */
    private const MAX_ORIGINAL_BYTES = 25 * 1024 * 1024;

    /** What is asked of Microsoft 365 for each message read. */
    private const GRAPH_FIELDS = 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,internetMessageId,internetMessageHeaders,body,hasAttachments,isRead';

    /** The folders of a Microsoft 365 mailbox never read (their well-known names): what it sent or is writing, junk, and the bin. */
    private const GRAPH_FOLDERS_NOT_READ = ['sentitems', 'outbox', 'drafts', 'junkemail', 'deleteditems'];

    /** @var array<string, string> mailboxes whose emails could not be marked as read this run, each with why — said once a mailbox */
    private array $notMarkedRead = [];

    public function __construct(
        private readonly SentFolder $mailboxes,
        private readonly OutlookCalendarService $graph,
        private readonly SentEmailArchive $archive,
        private readonly IncomingEmailMatcher $matcher,
    ) {}

    /**
     * Every mailbox a letter or email has gone from, read for what came in
     * since it was last read: replies to the emails sent, and any other
     * email from a matter's party — never further back than lookBackDays().
     * Or — for one matter — those days, for its emails only.
     *
     * With $maxSeconds it stops starting new messages once that time is up, and saves how far each
     * mailbox got, so the next run goes on from there (an automatic run must fit in the web cron's
     * time — a run the server cuts off would otherwise start over every time).
     *
     * @return array{replies: int, emails: int, sent: int, unfiled: int, errors: list<string>, stopped: bool, unlinked: list<array{matter: int, label: string, url: string, downloads: list<array{name: string, url: string}>}>}
     */
    public function collect(?int $matterId = null, ?int $maxSeconds = null): array
    {
        $this->unlinked = [];
        $this->notMarkedRead = [];
        $result = ['replies' => 0, 'emails' => 0, 'sent' => 0, 'unfiled' => 0, 'errors' => [], 'stopped' => false, 'unlinked' => []];
        $deadline = $maxSeconds !== null ? microtime(true) + $maxSeconds : null;
        $automatic = $matterId === null;

        if ($automatic) {
            Setting::set(self::LAST_RUN, ['started_at' => now()->toDateTimeString(), 'finished_at' => null, 'stopped' => false] + ((array) Setting::get(self::LAST_RUN, [])), 'mail', 'json');
        }

        $sent = MatterEmail::query()
            ->where('direction', MatterEmail::SENT)
            ->where('at', '>=', now()->subDays(self::WATCH_DAYS))
            ->when($matterId, fn ($q) => $q->where('matter_id', $matterId))
            ->get()
            ->groupBy('sender_key');

        // Every mailbox set to be read (Mail senders, Mailbox folders, a user's own) — whether or not anything was
        // ever sent from it: a shared one the parties write to first still receives.
        $mailboxes = array_map('strval', array_keys(SenderMailer::collected()));

        // The mailbox read longest ago first: one a short run did not reach goes first next time, so a
        // slow mailbox never keeps the others waiting.
        usort($mailboxes, fn (string $a, string $b): int => strcmp((string) Setting::get(self::checkedKey($a), ''), (string) Setting::get(self::checkedKey($b), '')));
        $read = 0;

        foreach ($mailboxes as $senderKey) {
            $emails = $sent->get($senderKey, collect());

            // Out of time: the mailboxes not reached are read next time (at least one is read each run).
            if ($deadline !== null && $read > 0 && microtime(true) >= $deadline) {
                $result['stopped'] = true;

                break;
            }

            $read++;

            try {
                $sender = SenderMailer::sender($senderKey);
                $checked = $matterId ? null : Setting::get(self::checkedKey($senderKey));
                // From where the mailbox was last read — never further back than the days set.
                $first = now()->subDays(self::lookBackDays());
                $since = $checked ? Carbon::parse($checked)->subHour()->max($first) : $first;
                $startedAt = now();

                $messages = SenderMailer::isMicrosoft($sender)
                    ? $this->fromGraph((string) $sender['address'], $since)
                    : $this->fromImap($senderKey, $sender, $since);

                $progress = ['reached' => null, 'stopped' => false];

                foreach ($this->handleAll($messages, $emails, $senderKey, $matterId, $deadline, $progress, $checked ? Carbon::parse($checked) : null) as $kind => $count) {
                    $result[$kind] += $count;
                }

                // Kept, but not marked as read in the mailbox (Microsoft 365 without Mail.ReadWrite): said once.
                if (isset($this->notMarkedRead[$senderKey])) {
                    $result['errors'][] = __('The emails kept from :mailbox could not be marked as read there: :error', ['mailbox' => $sender['name'] ?? $senderKey, 'error' => $this->notMarkedRead[$senderKey]]);
                }

                if ($automatic) {
                    if ($progress['stopped']) {
                        // Stopped for time: from the last message handled, not from the start again.
                        $result['stopped'] = true;
                        if ($progress['reached'] !== null && (! $checked || $progress['reached']->gt(Carbon::parse($checked)))) {
                            Setting::set(self::checkedKey($senderKey), $progress['reached']->toDateTimeString(), 'mail');
                        }
                    } else {
                        Setting::set(self::checkedKey($senderKey), $startedAt->toDateTimeString(), 'mail');
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Emails not collected from '.$senderKey.': '.$e->getMessage());
                $result['errors'][] = $senderKey.': '.$e->getMessage();
            }
        }

        $result['unlinked'] = array_values($this->unlinked);

        if ($automatic) {
            Setting::set(self::LAST_RUN, [
                'started_at' => (string) (((array) Setting::get(self::LAST_RUN, []))['started_at'] ?? now()->toDateTimeString()),
                'finished_at' => now()->toDateTimeString(),
                'kept' => ['replies' => $result['replies'], 'emails' => $result['emails'], 'sent' => $result['sent'], 'unfiled' => $result['unfiled']],
                'stopped' => $result['stopped'],
                'errors' => $result['errors'],
            ], 'mail', 'json');
        }

        return $result;
    }

    /**
     * What the last automatic run did, in a line: "17/10/2026 10:40 — 2 kept", its errors, or that it
     * has never run (the scheduler is not called) or is running (or was cut off).
     */
    public static function lastRunSummary(): string
    {
        $last = (array) Setting::get(self::LAST_RUN, []);

        if (blank($last['started_at'] ?? null)) {
            return __('Never — the scheduled tasks are not running (check the cron link).');
        }

        $started = Carbon::parse($last['started_at']);

        if (blank($last['finished_at'] ?? null)) {
            return __('Started :when and has not finished (still running, or it was cut off).', ['when' => $started->format(Dates::dateTimeFormat())]);
        }

        $kept = array_sum((array) ($last['kept'] ?? []));
        $line = Carbon::parse($last['finished_at'])->format(Dates::dateTimeFormat()).' — '.trans_choice('{0} nothing new|{1} 1 email kept|[2,*] :count emails kept', $kept, ['count' => $kept]);

        if ($last['stopped'] ?? false) {
            $line .= ' — '.__('more to read on the next run');
        }

        if (($errors = (array) ($last['errors'] ?? [])) !== []) {
            $line .= ' — '.__('Error: :error', ['error' => implode(' | ', $errors)]);
        }

        return $line;
    }

    /**
     * Whether the automatic run is due: never run yet, the last one stopped for time (a backlog is
     * left), or it started ten minutes ago or more (a run that was cut off counts as started).
     */
    public static function isDue(): bool
    {
        $last = (array) Setting::get(self::LAST_RUN, []);

        // A backlog is gone on at once — but only after a run that finished: one that was cut off
        // (PHP died in it) waits its ten minutes, not retried every minute.
        if (blank($last['started_at'] ?? null) || (filled($last['finished_at'] ?? null) && ($last['stopped'] ?? false))) {
            return true;
        }

        return Carbon::parse($last['started_at'])->lte(now()->subMinutes(self::EVERY_MINUTES)->addSeconds(5));
    }

    /**
     * A matter whose email was kept without a OneDrive folder to go to: said at the end of the round,
     * with the email's files to download and put there by hand.
     *
     * @param  list<array{name: string, path: string}>  $kept  where each file of the email was kept
     * @return list<array{name: string, url: string}> those to download — empty when the matter has its folder
     */
    private function noteUnlinked(Matter $matter, array $kept = []): array
    {
        if (! MatterOneDriveFolders::receivedEmailsNotInOneDrive($matter)) {
            return [];
        }

        $downloads = array_values(array_map(
            fn (array $file): array => ['name' => (string) $file['name'], 'url' => $this->downloadUrl($matter, (string) $file['path'])],
            array_filter($kept, fn (array $file) => filled($file['path'] ?? null)),
        ));

        $entry = $this->unlinked[$matter->getKey()] ?? [
            'matter' => (int) $matter->getKey(),
            'label' => $matter->year.'/'.$matter->number,
            'url' => MatterResource::getUrl('view', ['record' => $matter], panel: 'mms'),
            'downloads' => [],
        ];
        $entry['downloads'] = [...$entry['downloads'], ...$downloads];
        $this->unlinked[$matter->getKey()] = $entry;

        // At least the matter is told, even when nothing was kept to download.
        return $downloads ?: [['name' => '', 'url' => '']];
    }

    /** Where to download a file kept for an email: through the matter's attachment it is, else by its path. */
    private function downloadUrl(Matter $matter, string $path): string
    {
        return $matter->attachments()->where('path', $path)->first()?->url() ?? MatterEmail::fileUrl($path);
    }

    /**
     * Messages from an inbox, kept: replies to the emails sent, and —
     * given the mailbox they came to — the parties' other emails. How many.
     *
     * @param  iterable<array<string, mixed>>  $messages
     * @param  Collection<int, MatterEmail>  $sent
     */
    public function handle(iterable $messages, Collection $sent, ?string $senderKey = null, ?int $matterId = null, ?float $deadline = null, ?array &$progress = null, ?CarbonInterface $checkpoint = null): int
    {
        return array_sum($this->handleAll($messages, $sent, $senderKey, $matterId, $deadline, $progress, $checkpoint));
    }

    /**
     * Each message: message_id, references (ids), subject, from, from_name,
     * to, cc, at, and — fetched only for one kept — body and attachments.
     * For one matter ($matterId): only what is that matter's, nothing held.
     *
     * @param  iterable<array<string, mixed>>  $messages
     * @param  Collection<int, MatterEmail>  $sent
     * @return array{replies: int, emails: int, sent: int, unfiled: int}
     */
    private function handleAll(iterable $messages, Collection $sent, ?string $senderKey, ?int $matterId, ?float $deadline = null, ?array &$progress = null, ?CarbonInterface $checkpoint = null): array
    {
        $startedAt = now();
        $kept = ['replies' => 0, 'emails' => 0, 'sent' => 0, 'unfiled' => 0];
        // Whether an address is the office's own (a mailbox's, the office's domain, a user's) — asked once an address.
        $ours = [];
        $isOurs = function (string $address) use (&$ours): bool {
            return $ours[$address] ??= MatterContact::isOurs($address);
        };
        $progress = ['reached' => null, 'stopped' => false];
        $keepAll = self::keepsAll($senderKey);
        $filing = self::isFilingInbox($senderKey);
        $ignored = self::ignoredSenders();
        // The emails sent from our other mailboxes, read once — only when one is not answered from this one's.
        $read = null;
        $elsewhere = function () use ($sent, $senderKey, $matterId, &$read): Collection {
            return $read ??= MatterEmail::query()
                ->where('direction', MatterEmail::SENT)
                ->where('at', '>=', now()->subDays(self::WATCH_DAYS))
                ->when($senderKey !== null, fn ($q) => $q->where('sender_key', '!=', $senderKey))
                ->when($matterId, fn ($q) => $q->where('matter_id', $matterId))
                ->whereNotIn('id', $sent->map(fn (MatterEmail $email): int => (int) $email->getKey())->all())
                ->get();
        };

        foreach ($messages as $message) {
            // Out of time: what is left is read next time, from the last message handled — but only once
            // this run has got past where the last one stopped, so every run moves forward (reading the
            // mailbox's list, or the overlap read again, may take the time on its own).
            if ($deadline !== null && microtime(true) >= $deadline
                && $progress['reached'] !== null && ($checkpoint === null || $progress['reached']->gt($checkpoint))) {
                $progress['stopped'] = true;

                break;
            }

            $id = MatterEmail::messageId($message['message_id'] ?? null);
            // How far the mailbox has been read — messages come oldest first, and a stop for time
            // only ever happens before the next one is begun.
            $at = isset($message['at']) ? self::arrivedAt($message) : null;
            // A Date header in the future (a wrong clock) or an unreadable one must not move it past the rest.
            if ($at !== null && $at->lte($startedAt) && $at->year > 1971 && ($progress['reached'] === null || $at->gt($progress['reached']))) {
                $progress['reached'] = $at;
            }

            // The filing mailbox: each email in it is an instruction to file another (ForwardedEmailFiler), never
            // correspondence — read once, then marked read.
            if ($filing) {
                if ($id !== null && app(ForwardedEmailFiler::class)->handle($message, $id, (string) $senderKey, $this) !== null) {
                    $this->markRead($message, $senderKey);
                }

                continue;
            }

            // From an address on the ignore list: not read at all — not kept, not told, left unread.
            if (self::isIgnored((string) ($message['from'] ?? ''), $ignored)) {
                continue;
            }

            // Kept already — but one that was cut off half way (its row made, its files never kept) is
            // kept again by the automatic run: only once it is clearly abandoned (older than any run
            // lasts, so not another run still keeping it) and only a few times (one that kills PHP
            // every time must not stop the mailbox for ever).
            if ($id !== null) {
                if ($matterId === null) {
                    $this->retryAbandoned($id);
                }

                if (MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->where('message_id', $id)->exists()) {
                    $this->readAsKept($message, $id, $senderKey);

                    continue;
                }
            }

            // Bodies read once, however often asked for.
            $message['body'] = self::once($message['body'] ?? '');
            $message['attachments'] = self::once($message['attachments'] ?? []);

            // One email, kept once: the same one in two of our mailboxes (an assistant's, and a shared one
            // copied in), or read by two runs at once (the automatic one and a matter's "Check for emails")
            // — whichever claims it first keeps it; the other finds it kept.
            $claim = $id !== null ? Cache::lock('matter-email:'.sha1(mb_strtolower($id)), 600) : null;
            try {
                if ($claim !== null && ! $claim->get()) {
                    continue;
                }
            } catch (Throwable) {
                $claim = null; // A cache store without locks: the check below alone.
            }

            try {
                if ($id !== null && MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->where('message_id', $id)->exists()) {
                    $this->readAsKept($message, $id, $senderKey);

                    continue;
                }

                // From one of the office's own addresses: never a party's — a copy of what the office sent, read and
                // left; one written from Outlook to someone outside, kept on its matter as sent.
                $from = mb_strtolower(trim((string) ($message['from'] ?? '')));
                if ($from !== '' && $isOurs($from)) {
                    $this->handleOwn($message, $id, $senderKey, $from, $matterId, $isOurs, $kept);

                    continue;
                }

                // Sent to two of our addresses, some senders' servers send each its own copy, each with its own
                // Message-ID: the one kept from the other mailbox stands — this copy read here, not kept again.
                if (self::twinKept($message, $senderKey)) {
                    if (($message['was_read'] ?? null) === false) {
                        $this->markRead($message, $senderKey);
                    }

                    continue;
                }

                // Sent from this mailbox, else from another of ours: a reply can come to a mailbox other than the
                // one it answers (one copied in, read first).
                if ($answers = self::answers($message, $sent) ?? self::answers($message, $elsewhere())) {
                    if ($matterId === null || (int) $answers->matter_id === $matterId) {
                        $this->keep($answers, $message, $id);
                        $this->markRead($message, $senderKey);
                        $kept['replies']++;
                    }

                    continue;
                }

                // Any other: from a party, about a matter of theirs — or, when set to keep every email,
                // from anyone. Only one that can be told apart.
                if ($senderKey === null || $id === null || $from === '') {
                    continue;
                }

                $match = $this->matcher->match($from, (string) ($message['subject'] ?? ''), $message['body']);

                if ($match['matter'] !== null && ($matterId === null || $match['matter'] === $matterId)) {
                    $this->keepIncoming(Matter::findOrFail($match['matter']), $message, $id, $senderKey, $match['by']);
                    $this->markRead($message, $senderKey);
                    $kept['emails']++;
                } elseif ($match['matter'] === null && $matterId === null && ($match['candidates'] !== [] || $keepAll)) {
                    // Its matter not certain — or none at all (a stranger's email, kept when set to keep
                    // every one): a person files it, or sets it aside, on the Unfiled emails page.
                    $this->hold($message, $id, $senderKey, $match['candidates']);
                    $this->markRead($message, $senderKey);
                    $kept['unfiled']++;
                }
            } catch (Throwable $e) {
                // Its row, if it was made, stays without files: once abandoned long enough, the
                // automatic run tries it again (retryAbandoned()).
                Log::warning('Email not kept: '.$e->getMessage(), ['message' => $id]);
            } finally {
                $claim?->release();
            }
        }

        return $kept;
    }

    /**
     * Whether this mailbox keeps every email, not only those from a matter's party (the others go to Unfiled
     * emails) — each mailbox's own choice (Mail senders). With no mailbox named: whether any one does.
     */
    public static function keepsAll(?string $senderKey = null): bool
    {
        $senders = SenderMailer::all();

        return $senderKey !== null
            ? (bool) ($senders[$senderKey]['keep_all'] ?? false)
            : collect($senders)->contains(fn (array $sender): bool => (bool) ($sender['keep_all'] ?? false));
    }

    /** Whether this is the filing mailbox (Mail senders): what the office forwards to it is filed as its original. */
    public static function isFilingInbox(?string $senderKey): bool
    {
        return $senderKey !== null && (bool) (SenderMailer::all()[$senderKey]['forwards'] ?? false);
    }

    /** Setting: addresses (or "@domain" for a whole domain) whose emails are never read. */
    public const IGNORED = 'mail_ignored_senders';

    /**
     * @return list<string> lower case
     */
    public static function ignoredSenders(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($entry): string => mb_strtolower(trim((string) $entry)),
            (array) Setting::get(self::IGNORED, []),
        ))));
    }

    /**
     * Whether an address is on the ignore list: itself, or its domain written as "@domain".
     *
     * @param  list<string>|null  $list
     */
    public static function isIgnored(string $from, ?array $list = null): bool
    {
        $from = mb_strtolower(trim($from));

        if ($from === '') {
            return false;
        }

        foreach ($list ?? self::ignoredSenders() as $entry) {
            if ($entry === $from || (str_starts_with($entry, '@') && str_ends_with($from, $entry))) {
                return true;
            }
        }

        return false;
    }

    /**
     * An address put on the ignore list, and its emails waiting on the Unfiled emails page set aside.
     * Returns how many were set aside.
     */
    public function ignore(string $address): int
    {
        $address = mb_strtolower(trim($address));

        if ($address === '') {
            return 0;
        }

        Setting::set(self::IGNORED, array_values(array_unique([...self::ignoredSenders(), $address])), 'mail');

        // Narrowed by the database first (the address, or "@domain", is in "from"), then matched exactly.
        $waiting = MatterEmail::pending()->where('from', 'like', '%'.addcslashes($address, '%_\\').'%')->get()->filter(fn (MatterEmail $email): bool => self::isIgnored(MatterEmail::addressOf((string) $email->from), [$address]));
        $waiting->each(fn (MatterEmail $email) => $this->dismiss($email));

        return $waiting->count();
    }

    /** Headers read at a time from a cPanel inbox. */
    private const IMAP_PAGE = 50;

    /** Minutes after which a half-kept email counts as abandoned — longer than any run lasts. */
    private const ABANDONED_MINUTES = 20;

    /** How many times a half-kept email is kept again before it is left as it is. */
    private const RETRIES = 3;

    /**
     * An email whose row was made but whose files were never kept (the run was cut off, or keeping
     * it failed): its row goes so it is kept again — when abandoned, and up to RETRIES times. After
     * that it is left with no files (and logged), so it no longer holds the mailbox up.
     */
    private function retryAbandoned(string $id): void
    {
        $half = MatterEmail::query()
            ->where('direction', MatterEmail::RECEIVED)
            ->where('message_id', $id)
            ->whereNull('files')
            ->where('created_at', '<=', now()->subMinutes(self::ABANDONED_MINUTES))
            ->get();

        if ($half->isEmpty()) {
            return;
        }

        $tries = (int) Cache::get('mail-keep-retries:'.$id, 0);

        if ($tries >= self::RETRIES) {
            Log::warning('Email left half kept after '.self::RETRIES.' tries', ['message' => $id]);
            $half->each(fn (MatterEmail $email) => $email->update(['files' => []]));

            return;
        }

        Cache::put('mail-keep-retries:'.$id, $tries + 1, now()->addDays(7));
        $half->each->delete();
    }

    /**
     * The email a message answers: the one it names, else the latest of that
     * subject sent before it to whoever it's from.
     *
     * @param  array<string, mixed>  $message
     * @param  Collection<int, MatterEmail>  $sent
     */
    public static function answers(array $message, Collection $sent): ?MatterEmail
    {
        $references = array_filter(array_map([MatterEmail::class, 'messageId'], (array) ($message['references'] ?? [])));
        if ($references !== []) {
            $named = $sent->first(fn (MatterEmail $email) => $email->message_id !== null && in_array($email->message_id, $references, true));
            if ($named) {
                return $named;
            }
        }

        $from = mb_strtolower(trim((string) ($message['from'] ?? '')));
        $subject = self::bareSubject((string) ($message['subject'] ?? ''));
        $at = isset($message['at']) ? Carbon::parse($message['at']) : now();

        if ($from === '' || mb_strlen($subject) < 4) {
            return null;
        }

        return $sent
            ->filter(fn (MatterEmail $email) => in_array($from, array_map(fn ($address): string => mb_strtolower((string) $address), [...(array) $email->to, ...(array) $email->cc]), true)
                && $email->at->lte($at)
                && ($bare = self::bareSubject($email->subject)) !== ''
                && ($bare === $subject || str_contains($subject, $bare)))
            ->sortByDesc('at')
            ->first();
    }

    /** "RE: Fwd: رد: The subject" as "the subject" — to compare. */
    public static function bareSubject(string $subject): string
    {
        $subject = trim($subject);

        do {
            $before = $subject;
            $subject = trim((string) preg_replace('/^(re|fw|fwd|aw|sv|wg|tr|رد|إعادة توجيه|اعادة توجيه|توجيه)\s*(\[\d+\])?\s*[:：]\s*/iu', '', $subject));
        } while ($subject !== $before);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $subject)));
    }

    /**
     * A message that is kept in the system is read in the mailbox too, so the
     * inbox shows what is still to be looked at. Never stops the collecting: a
     * mailbox that does not allow it (Microsoft 365 needs Mail.ReadWrite) is
     * only logged.
     *
     * @param  array<string, mixed>  $message
     */
    /**
     * Kept already — from another of our mailboxes it came to as well (an assistant's, a shared one copied
     * in): read in this one too, as it is where it was kept. Only while unread here: not again each run.
     *
     * @param  array<string, mixed>  $message
     */
    private function readAsKept(array $message, string $id, ?string $senderKey): void
    {
        if (($message['was_read'] ?? null) !== false || $senderKey === null) {
            return;
        }

        // Not where it was kept: there, made unread again on purpose (undone), it stays so.
        $keptFrom = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->where('message_id', $id)->value('sender_key');
        if ($keptFrom !== null && $keptFrom !== $senderKey) {
            $this->markRead($message, $senderKey);
        }
    }

    /**
     * An email from one of the office's own addresses, in one of our mailboxes. A copy of one the office sent from
     * Wakeel (a letter, minutes, a bulk email — copied to another of our mailboxes): read there, nothing recorded,
     * it is on its matter already. One written outside Wakeel (from Outlook) to someone outside the office: kept on
     * its matter as sent — its PDF and files in the sent-emails folder, a step in the progress, the matter's people
     * told, and its replies watched — when the matter is certain (IncomingEmailMatcher, by the outside addresses it
     * went to and what it says). Between office people only, or about no certain matter: left as it is.
     *
     * @param  array<string, mixed>  $message
     * @param  Closure(string): bool  $isOurs
     * @param  array{replies: int, emails: int, sent: int, unfiled: int}  $kept
     */
    private function handleOwn(array $message, ?string $id, ?string $senderKey, string $from, ?int $matterId, Closure $isOurs, array &$kept): void
    {
        if (self::isOurSentCopy($message, $id, $from, $isOurs)) {
            if (($message['was_read'] ?? null) === false) {
                $this->markRead($message, $senderKey);
            }

            return;
        }

        if ($senderKey === null || $id === null) {
            return;
        }

        $outside = self::outsideAddresses($message, $isOurs);
        if ($outside === []) {
            return;
        }

        // A reply to an email that came in (Outlook's In-Reply-To): its matter is that email's, and it is kept
        // beside that email, in its folder.
        $answered = self::answeredReceived($message);
        $match = $answered !== null
            ? ['matter' => (int) $answered->matter_id, 'by' => MatterEmail::BY_REPLY]
            : $this->matterWrittenTo($outside, (string) ($message['subject'] ?? ''), $message['body']);
        if ($match === null || ($matterId !== null && $match['matter'] !== $matterId)) {
            return;
        }

        $this->keepSentByOffice(Matter::findOrFail($match['matter']), $message, $id, $senderKey, $from, $outside, $match['by'], $answered);
        $this->markRead($message, $senderKey);
        $kept['sent']++;
    }

    /**
     * The email that came in on a matter that this one answers — named in its In-Reply-To or References — or null.
     *
     * @param  array<string, mixed>  $message
     */
    private static function answeredReceived(array $message): ?MatterEmail
    {
        $references = array_values(array_filter(array_map([MatterEmail::class, 'messageId'], (array) ($message['references'] ?? []))));

        return $references === [] ? null : MatterEmail::query()
            ->where('direction', MatterEmail::RECEIVED)
            ->whereNotNull('matter_id')
            ->whereIn('message_id', $references)
            ->first();
    }

    /**
     * The addresses outside the office an email went to (To and Cc), lower case, each once.
     *
     * @param  array<string, mixed>  $message
     * @param  Closure(string): bool  $isOurs
     * @return list<string>
     */
    private static function outsideAddresses(array $message, Closure $isOurs): array
    {
        return collect([...(array) ($message['to'] ?? []), ...(array) ($message['cc'] ?? [])])
            ->map(fn ($address): string => mb_strtolower(trim((string) $address)))
            ->filter()
            ->unique()
            ->reject(fn (string $address): bool => $isOurs($address))
            ->values()
            ->all();
    }

    /**
     * Whether an email from one of our addresses is a copy of one the office sent from Wakeel: its Message-ID is
     * a sent email's — or, as Microsoft 365 gives sent mail its own id, it went from one of our mailboxes with
     * the same subject to the same outside addresses within minutes of a sent email of that mailbox.
     *
     * @param  array<string, mixed>  $message
     * @param  Closure(string): bool  $isOurs
     */
    private static function isOurSentCopy(array $message, ?string $id, string $from, Closure $isOurs): bool
    {
        if ($id !== null && MatterEmail::query()->where('direction', MatterEmail::SENT)->where('message_id', $id)->exists()) {
            return true;
        }

        $mailboxes = array_keys(array_filter(SenderMailer::all(), fn (array $sender): bool => mb_strtolower((string) ($sender['address'] ?? '')) === $from));
        $subject = mb_substr((string) ($message['subject'] ?? ''), 0, 500);

        if ($mailboxes === [] || trim($subject) === '') {
            return false;
        }

        $at = self::arrivedAt($message);
        $went = self::outsideAddresses($message, $isOurs);

        return MatterEmail::query()
            ->where('direction', MatterEmail::SENT)
            ->whereIn('sender_key', array_map('strval', $mailboxes))
            ->where('subject', $subject)
            ->whereBetween('at', [$at->copy()->subMinutes(MatterEmail::COPY_MINUTES), $at->copy()->addMinutes(MatterEmail::COPY_MINUTES)])
            ->get(['to', 'cc'])
            ->contains(function (MatterEmail $sent) use ($went): bool {
                $to = array_map(fn ($address): string => mb_strtolower(trim((string) $address)), [...(array) $sent->to, ...(array) $sent->cc]);

                return array_diff($went, $to) === [];
            });
    }

    /**
     * The matter an office email to these outside addresses is about, when it is certain: the one the matcher
     * names for the addresses it went to and what it says — one and the same for all of them.
     *
     * @param  list<string>  $outside
     * @return array{matter: int, by: ?string}|null
     */
    private function matterWrittenTo(array $outside, string $subject, Closure|string $body): ?array
    {
        $certain = [];

        foreach ($outside as $address) {
            $match = $this->matcher->match($address, $subject, $body);

            if ($match['matter'] !== null) {
                $certain[$match['matter']] ??= ['matter' => (int) $match['matter'], 'by' => $match['by']];
            }
        }

        return count($certain) === 1 ? array_values($certain)[0] : null;
    }

    /**
     * An email the office wrote from Outlook to someone outside, kept on its matter as sent: its PDF (and the
     * files it went with, and its original) in the sent-emails folder, a step in the progress, its contacts
     * learned, the matter's people told — its replies looked for in the mailbox it went from, when that is one
     * of ours; else in the one it was read from.
     *
     * @param  array<string, mixed>  $message
     * @param  list<string>  $outside  whom outside the office it went to
     */
    private function keepSentByOffice(Matter $matter, array $message, string $id, string $senderKey, string $from, array $outside, ?string $by, ?MatterEmail $answered = null): MatterEmail
    {
        $mailbox = collect(SenderMailer::all())->search(fn (array $sender): bool => mb_strtolower((string) ($sender['address'] ?? '')) === $from);
        $key = $mailbox !== false ? (string) $mailbox : $senderKey;
        $userId = User::query()->whereRaw('lower(email) = ?', [$from])->value('id');
        $lower = fn (mixed $list): array => array_values(array_unique(array_filter(array_map(fn ($address): string => mb_strtolower(trim((string) $address)), (array) $list))));
        $at = self::arrivedAt($message);

        $email = $this->record($message, $id, $key, [
            'matter_id' => $matter->getKey(),
            'direction' => MatterEmail::SENT,
            'status' => MatterEmail::FILED,
            'matched_by' => $by,
            'user_id' => $userId,
            'to' => $lower($message['to'] ?? []),
            'cc' => $lower($message['cc'] ?? []),
        ]);
        [$pdf, $files, , $source, $links] = $this->rendered($message, $key, false, $matter, received: false);
        $name = SentEmailArchive::emailName(true, self::partyName($outside[0]) ?? $outside[0], (string) ($message['subject'] ?? ''), $at, false);

        // A reply: beside the email it answers, in its folder; else where the sent emails go (a creditor's, the sent folder).
        $folder = $answered !== null && $answered->matter_id === $matter->getKey() ? MatterOneDriveFolders::emailFolder($answered, $matter) : '';
        $kept = $this->keepIn($matter, $folder !== '' ? $folder : MatterOneDriveFolders::sentEmailsFolderFor($matter, $at, $outside), $pdf, $files, $name, $userId, $source, $oneDrive);
        $email->update(['files' => $kept, 'links' => $links, 'onedrive' => $oneDrive]);

        MatterProgressRecorder::emailSentByHand($email, $userId);
        MatterContact::learnSent($email);
        MatterEmailSentNotifier::note($email);
        $this->noteUnlinked($matter, $kept);

        return $email;
    }

    /**
     * The same email kept already from another of our mailboxes under another Message-ID: from the same sender,
     * with the same subject, To and Cc, within minutes — a copy the sender's server made for each of our addresses.
     *
     * @param  array<string, mixed>  $message
     */
    private static function twinKept(array $message, ?string $senderKey): bool
    {
        $from = mb_strtolower(trim((string) ($message['from'] ?? '')));
        $subject = mb_substr((string) ($message['subject'] ?? ''), 0, 500);

        if ($senderKey === null || $from === '' || trim($subject) === '') {
            return false;
        }

        $at = self::arrivedAt($message);
        $addresses = fn (mixed $list): array => collect((array) $list)->map(fn ($address): string => mb_strtolower(trim((string) $address)))->filter()->unique()->sort()->values()->all();
        $to = $addresses($message['to'] ?? []);
        $cc = $addresses($message['cc'] ?? []);

        return MatterEmail::query()
            ->where('direction', MatterEmail::RECEIVED)
            ->where('sender_key', '!=', $senderKey)
            ->where('subject', $subject)
            ->whereBetween('at', [$at->copy()->subMinutes(self::TWIN_MINUTES), $at->copy()->addMinutes(self::TWIN_MINUTES)])
            ->get(['from', 'to', 'cc'])
            ->contains(fn (MatterEmail $kept): bool => (mb_strtolower((string) $kept->from) === $from || str_ends_with(mb_strtolower((string) $kept->from), '<'.$from.'>'))
                && $addresses($kept->to) === $to
                && $addresses($kept->cc) === $cc);
    }

    private function markRead(array $message, ?string $senderKey = null): void
    {
        if (! ($message['mark_read'] ?? null) instanceof Closure) {
            return;
        }

        try {
            ($message['mark_read'])();
        } catch (Throwable $e) {
            Log::info('Email not marked as read in the mailbox: '.$e->getMessage(), ['message' => $message['message_id'] ?? null]);
            // Said once a mailbox in the run's result (collect()), not once an email.
            if ($senderKey !== null) {
                $this->notMarkedRead[$senderKey] ??= $e->getMessage();
            }
        }
    }

    /**
     * A collected email made unread again in the mailbox it came to — found there by its Message-ID.
     * Whether it was found and changed.
     */
    public function markUnread(MatterEmail $email): bool
    {
        $id = (string) $email->message_id;
        $sender = SenderMailer::all()[(string) $email->sender_key] ?? null;

        if ($id === '' || ! is_array($sender)) {
            return false;
        }

        if (SenderMailer::isMicrosoft($sender)) {
            $base = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode((string) $sender['address']);
            $found = Http::withToken($this->graph->getAccessToken())->timeout(30)
                ->get($base.'/messages', ['$filter' => "internetMessageId eq '".str_replace("'", "''", '<'.$id.'>')."'", '$select' => 'id'])
                ->json('value.0.id');

            return is_string($found)
                && Http::withToken($this->graph->getAccessToken())->timeout(20)->patch($base.'/messages/'.rawurlencode($found), ['isRead' => false])->successful();
        }

        $message = $this->mailboxes->inbox((string) $email->sender_key, $sender)->query()
            ->whereMessageId('<'.$id.'>')->leaveUnread()->setFetchBody(false)->get()->first();

        return $message !== null && $message->unsetFlag('Seen');
    }

    /**
     * A received email made again from the mailbox it came to — found there by its Message-ID: its PDF
     * made anew, its original (.eml) and the files it came with downloaded again, under its name as made
     * now (short enough for a OneDrive synced to a PC): its old copies in OneDrive — PDF, original, the
     * folder of its files — to OneDrive's recycle bin, the new ones in their place; its PDF kept with the
     * matter's attachments in place (its link the same), renamed. For a PDF made wrongly (an email's own
     * charset taken at its word), or named too long.
     *
     * @return array{pdf: bool, eml: bool, attachments: int}
     */
    public function regenerate(MatterEmail $email, ?int $userId = null): array
    {
        $userId ??= auth()->id();

        abort_if($email->direction !== MatterEmail::RECEIVED, 422);

        $message = $this->findMessage($email)
            ?? throw new RuntimeException(__('This email is no longer in the mailbox it came to, so it cannot be made again.'));

        [$pdf, $files, $name, $source, $links, $side, $from, $party] = $this->rendered($message, (string) $email->sender_key, $email->parent_id !== null, $email->matter);
        // Its links read again — one of before read for them for the first time; its text kept, for a reply to quote.
        $email->update(['links' => $links, 'body' => $message['body'] instanceof Closure ? (string) ($message['body'])() : (string) ($message['body'] ?? '')]);

        $old = (array) ($email->files ?? []);
        $replace = function (?string $path, string $contents): bool {
            if (! $disk = Documents::storage($path)) {
                return false;
            }
            $disk->put($path, $contents);
            Attachment::query()->where('path', $path)->update(['size' => strlen($contents)]);

            return true;
        };
        // A file it came with that was kept in the system: by the name it was kept under, long or short.
        $oldPath = fn (string $fileName): ?string => collect($old)->first(fn (array $file): bool => in_array($file['name'] ?? null, [$fileName, SentEmailArchive::shortFileName($fileName)], true) && filled($file['path'] ?? null))['path'] ?? null;

        $matter = $email->matter;
        $done = ['pdf' => false, 'eml' => false, 'attachments' => 0];

        // Of no matter yet: its files kept in the system only.
        if (! $matter) {
            $done['pdf'] = $replace($old[0]['path'] ?? null, $pdf);
            foreach ($files as $file) {
                $done['attachments'] += $replace($oldPath((string) $file['name']), (string) $file['contents']) ? 1 : 0;
            }
            $eml = collect($old)->first(fn (array $file): bool => ($file['kind'] ?? null) === MatterEmail::SOURCE);
            $done['eml'] = $source !== null && $replace($eml['path'] ?? null, $source);

            return $done;
        }

        $folder = (string) (((array) $email->onedrive)['folder'] ?? '') ?: MatterOneDriveFolders::receivedEmailsFolderFor($matter, $email->at, $side, $from);
        $attachmentsFolder = (string) (((array) $email->onedrive)['attachments_folder'] ?? '') ?: MatterOneDriveFolders::attachmentsFolderFor($matter, $email->at, $side, $from, $party);

        // Its old copies in OneDrive, under the name it had, to the recycle bin — the new ones go under its name now.
        app(MatterOneDriveFolders::class)->removeEmailFiles($email, $matter);

        // The PDF: in its place with the matter's attachments (renamed), and in OneDrive.
        $inSystem = $replace($old[0]['path'] ?? null, $pdf);
        if ($inSystem) {
            Attachment::query()->where('path', $old[0]['path'])->update(['name' => $name.'.pdf']);
        }
        $done['pdf'] = $this->archive->keepInOneDrive($matter, $name.'.pdf', $pdf, 'application/pdf', $folder, replace: true) || $inSystem;

        // The original, beside it in OneDrive.
        if ($source !== null) {
            $done['eml'] = $this->archive->keepInOneDrive($matter, $name.'.eml', $source, 'message/rfc822', $folder, replace: true);
        }

        // The files it came with: OneDrive's copies replaced, those kept in the system in their place.
        $kept = [['name' => $name.'.pdf', 'path' => $old[0]['path'] ?? null]];
        foreach ($files as $file) {
            $path = $oldPath((string) $file['name']);
            $fileName = SentEmailArchive::shortFileName((string) $file['name']);
            $contents = (string) $file['contents'];

            // In OneDrive with the others — one OneDrive refused before (kept in the system then) too.
            $saved = $folder !== '' && $this->archive->keepInOneDrive($matter, $fileName, $contents, (string) ($file['mime'] ?? 'application/octet-stream'), $attachmentsFolder !== '' ? $attachmentsFolder : $folder.'/'.$name, replace: true);
            if ($saved && $path !== null) {
                // Its copy in the system is no longer needed.
                Attachment::query()->where('path', $path)->get()->each->forceDelete();
                Documents::delete($path);
            } elseif (! $saved && $path === null) {
                $path = $this->archive->keepFile($matter, $fileName, $contents, (string) ($file['mime'] ?? 'application/octet-stream'), '', $userId, inOneDrive: $ignored);
            } elseif (! $saved) {
                $replace($path, $contents);
            }

            $kept[] = ['name' => $fileName, 'path' => $saved ? null : $path];
            $done['attachments']++;
        }

        // A file kept in the system before under a name read wrongly (none of the files has it now): gone.
        $still = array_filter(array_column($kept, 'path'));
        foreach (array_slice($old, 1) as $file) {
            $path = $file['path'] ?? null;
            if (filled($path) && ($file['kind'] ?? null) !== MatterEmail::SOURCE && ! in_array($path, $still, true)) {
                Attachment::query()->where('path', $path)->get()->each->forceDelete();
                Documents::delete($path);
            }
        }

        $email->update([
            'files' => $kept,
            'onedrive' => [...((array) $email->onedrive), 'pdf' => $done['pdf'], 'eml' => $done['eml'], 'attachments' => $done['attachments'], 'folder' => $folder, ...($attachmentsFolder !== '' ? ['attachments_folder' => $attachmentsFolder] : [])],
        ]);

        return $done;
    }

    /**
     * A collected email, found again in the mailbox it came to by its Message-ID, read as when it was
     * collected. Null when it is gone from there.
     *
     * @return array<string, mixed>|null
     */
    /**
     * The text (HTML) of an email that came in, for a reply to quote: as kept, else read from the mailbox it came to
     * and kept — null when it is no longer there, or the mailbox cannot be read.
     */
    public function bodyOf(MatterEmail $email): ?string
    {
        if ($email->body !== null) {
            return $email->body;
        }

        try {
            $message = $this->findMessage($email);
            $body = $message === null ? null : ($message['body'] instanceof Closure ? ($message['body'])() : (string) ($message['body'] ?? ''));
        } catch (Throwable $e) {
            Log::warning('An email\'s text could not be read for a reply: '.$e->getMessage(), ['email' => $email->getKey()]);

            return null;
        }

        if ($body !== null) {
            $email->forceFill(['body' => $body])->save();
        }

        return $body;
    }

    private function findMessage(MatterEmail $email): ?array
    {
        $id = trim((string) $email->message_id, '<> ');
        $sender = SenderMailer::all()[(string) $email->sender_key] ?? null;

        if ($id === '' || ! is_array($sender)) {
            return null;
        }

        if (SenderMailer::isMicrosoft($sender)) {
            $mailbox = (string) $sender['address'];
            $base = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox);
            $item = Http::withToken($this->graph->getAccessToken())->timeout(30)
                ->get($base.'/messages', ['$filter' => "internetMessageId eq '".str_replace("'", "''", '<'.$id.'>')."'", '$select' => self::GRAPH_FIELDS])
                ->json('value.0');

            return is_array($item) ? $this->graphMessage($base, $mailbox, $item) : null;
        }

        $inbox = $this->mailboxes->inbox((string) $email->sender_key, $sender);
        // Searched for in the days around when it came first: a whole inbox searched by its Message-ID is
        // every message's headers read by the server (a minute on a big cPanel inbox); then everywhere.
        $search = fn (bool $near) => $inbox->query()->whereMessageId('<'.$id.'>')
            ->when($near, fn ($query) => $query->since($email->at->copy()->subDays(2))->before($email->at->copy()->addDays(3)))
            ->leaveUnread()->setFetchBody(false)->setFetchFlags(true)->get();

        foreach ($email->at ? [true, false] : [false] as $near) {
            foreach ($this->imapPage($inbox, $search($near), Carbon::create(1971)) as $message) {
                return $message;
            }
        }

        return null;
    }

    /**
     * A reply, kept.
     *
     * @param  array<string, mixed>  $message
     */
    private function keep(MatterEmail $answers, array $message, ?string $id): void
    {
        // A matter's — or a bulk mail campaign's with no matter.
        $matter = $answers->matter;

        $reply = $this->record($message, $id, (string) $answers->sender_key, [
            'matter_id' => $matter?->getKey(),
            'parent_id' => $answers->getKey(),
            // A reply on a matter is on it (listed in its Emails tab); one to a campaign with no matter is nobody's.
            'status' => $matter ? MatterEmail::FILED : null,
        ]);
        [$pdf, $files, $name, $source, $links, $side, $from, $party] = $this->rendered($message, (string) $answers->sender_key, true, $matter);
        $oneDrive = null;

        if ($matter) {
            $kept = $this->keepOnMatter($matter, $pdf, $files, $name, $answers->user_id, $source, $oneDrive, self::arrivedAt($message), $side, $from, $party);
        } else {
            // No matter: its PDF and the files it came with, in a folder of its own.
            $kept = $this->keepLoose('email-replies/'.$reply->getKey(), $pdf, $files, $name);
        }

        // Where each went — for the campaign's recipients list to open. Written first, so the steps
        // after it are never repeated by a run that keeps the email again.
        $reply->update(['files' => $kept, 'links' => $links, 'onedrive' => $oneDrive]);

        if ($matter) {
            $this->noteUnlinked($matter, $kept);
            MatterProgressRecorder::replyReceived($reply->setRelation('parent', $answers));
            // Whoever they put in To or CC: theirs, on this matter.
            MatterContact::learn($reply);
        }

        $this->tell($reply, $answers, count($files));
    }

    /**
     * A party's email — not a reply — about one matter, kept on it: its PDF
     * and files, a step in its progress, its people told.
     *
     * @param  array<string, mixed>  $message
     */
    private function keepIncoming(Matter $matter, array $message, string $id, string $senderKey, ?string $by, ?int $userId = null): MatterEmail
    {
        $email = $this->record($message, $id, $senderKey, ['matter_id' => $matter->getKey(), 'status' => MatterEmail::FILED, 'matched_by' => $by]);
        [$pdf, $files, $name, $source, $links, $side, $from, $party] = $this->rendered($message, $senderKey, false, $matter);

        $kept = $this->keepOnMatter($matter, $pdf, $files, $name, $userId, $source, $oneDrive, self::arrivedAt($message), $side, $from, $party);
        $email->update(['files' => $kept, 'links' => $links, 'onedrive' => $oneDrive]);
        MatterProgressRecorder::emailReceived($email, $userId);
        MatterContact::learn($email);
        // From a creditor: whoever it copied in is the creditor's too.
        app(MatterCreditors::class)->learnCopied($email, $side);
        // Forwarded by someone: they are told too, unread — it answers what they did.
        $this->tellMatter($email, $matter, count($files), $this->noteUnlinked($matter, $kept), $userId, filerReads: false);

        return $email;
    }

    /**
     * An email forwarded to the filing mailbox (ForwardedEmailFiler), kept on the matter named as it came from
     * its own sender: its PDF and files — and, forwarded as an attachment, the email itself (.eml).
     *
     * @param  array<string, mixed>  $original
     */
    public function keepForwarded(Matter $matter, array $original, string $senderKey, int $userId, string $forwardId): MatterEmail
    {
        // Its own Message-ID when it came whole; one made of the forward's when only quoted.
        $id = MatterEmail::messageId($original['message_id'] ?? null) ?? 'forwarded.'.$forwardId;

        return $this->keepIncoming($matter, $original, $id, $senderKey, MatterEmail::BY_FORWARD, $userId);
    }

    /**
     * A party's email whose matter is not certain: kept aside — its PDF and
     * files — until a person chooses (file()).
     *
     * @param  array<string, mixed>  $message
     * @param  list<int>  $candidates
     */
    private function hold(array $message, string $id, string $senderKey, array $candidates): MatterEmail
    {
        $email = $this->record($message, $id, $senderKey, ['status' => MatterEmail::PENDING, 'candidates' => $candidates]);
        [$pdf, $files, $name, $source, $links] = $this->rendered($message, $senderKey, false);

        $email->update(['files' => $this->keepLoose('email-unfiled/'.$email->getKey(), $pdf, $files, $name, $source), 'links' => $links]);
        $this->tellUnfiled($email);

        return $email;
    }

    /**
     * An email forwarded to the filing mailbox naming more than one matter (ForwardedEmailFiler): kept aside as
     * it came from its own sender, those matters' people asked which.
     *
     * @param  array<string, mixed>  $original
     * @param  list<int>  $candidates
     */
    public function holdForwarded(array $original, string $senderKey, array $candidates, string $forwardId): MatterEmail
    {
        return $this->hold($original, MatterEmail::messageId($original['message_id'] ?? null) ?? 'forwarded.'.$forwardId, $senderKey, $candidates);
    }

    /**
     * One waiting in Unfiled emails, now said to be about these matters (a forward naming them): their people
     * asked which.
     *
     * @param  list<int>  $candidates
     */
    public function askAbout(MatterEmail $email, array $candidates): MatterEmail
    {
        $email->update(['candidates' => array_values(array_map('intval', $candidates))]);
        $this->tellUnfiled($email);

        return $email;
    }

    /**
     * A held email, filed by a person on the matter they chose: its files
     * moved there (and to its OneDrive), a step in its progress.
     */
    public function file(MatterEmail $email, Matter $matter, ?int $userId = null): void
    {
        $stored = (array) ($email->files ?? []);
        $kept = [];
        // The PDF in the received-emails folder of its sender's side, named as that side's are; the original (.eml)
        // beside it, the files it came with in a folder of the PDF's name.
        $from = MatterEmail::addressOf((string) $email->from);
        $side = ReceivedEmailFiling::side($matter, $from);
        $folder = MatterOneDriveFolders::receivedEmailsFolderFor($matter, $email->at, $side, $from);
        $party = self::partyName($from) ?? (trim(Str::before((string) $email->from, '<')) ?: $from);
        $name = ReceivedEmailFiling::name($matter, $side, $party, (string) $email->subject, $email->at ?? now(), $email->parent_id !== null, $from);
        $attachmentsFolder = MatterOneDriveFolders::attachmentsFolderFor($matter, $email->at, $side, $from, $party);
        $oneDrive = ['pdf' => false, 'eml' => false, 'attachments' => 0, 'folder' => $folder, ...($attachmentsFolder !== '' ? ['attachments_folder' => $attachmentsFolder] : [])];

        // What it is made of, for a copy in each other capacity's folders (keepCopies()).
        $pdf = '';
        $source = null;
        $files = [];

        foreach ($stored as $i => $file) {
            $path = (string) ($file['path'] ?? '');
            if (! $disk = Documents::storage($path)) {
                continue;
            }

            // The original email (.eml) goes to OneDrive only, beside the PDF; it is not kept in the system.
            if (($file['kind'] ?? null) === MatterEmail::SOURCE) {
                $source = (string) $disk->get($path);
                $oneDrive['eml'] = $this->archive->keepInOneDrive($matter, $name.'.eml', $source, 'message/rfc822', $folder);
                $disk->delete($path);

                continue;
            }

            $contents = (string) $disk->get($path);
            $mime = (string) ($disk->mimeType($path) ?: 'application/octet-stream');

            if ($i === 0) {
                // The PDF: with the matter's attachments, and in OneDrive.
                $pdf = $contents;
                $kept[] = ['name' => $name.'.pdf', 'path' => $this->archive->keepFile($matter, $name.'.pdf', $contents, $mime, $folder, $userId, inOneDrive: $saved)];
                $oneDrive['pdf'] = (bool) $saved;
            } else {
                // A file it came with: in OneDrive only — in the side's attachments folder, else in a folder of the PDF's name.
                $files[] = ['name' => (string) $file['name'], 'contents' => $contents, 'mime' => $mime];
                $attachment = $this->keepAttachment($matter, (string) $file['name'], $contents, $mime, $attachmentsFolder !== '' ? $attachmentsFolder : ($folder !== '' ? $folder.'/'.$name : ''), $userId);
                $kept[] = ['name' => $attachment['name'], 'path' => $attachment['path']];
                $oneDrive['attachments'] += $attachment['saved'] ? 1 : 0;
            }

            $disk->delete($path);
        }

        if ($pdf !== '') {
            $this->keepCopies($matter, $pdf, $files, $name, $source, $email->at, $from, $side, $party, $oneDrive);
        }

        $email->update([
            'matter_id' => $matter->getKey(),
            'status' => MatterEmail::FILED,
            'matched_by' => MatterEmail::BY_HAND,
            'onedrive' => $oneDrive,
            // Every file listed, the PDF first — those in OneDrive only have no path here.
            'files' => $kept,
        ]);

        MatterProgressRecorder::emailReceived($email, $userId);
        MatterContact::learn($email);
        app(MatterCreditors::class)->learnCopied($email, $side);
        $this->setAsideCopies($email);

        // As for an email put on its matter by itself: its people hear of it — who chose it, already read.
        $this->answered($email);
        $this->tellMatter($email, $matter, max(count($kept) - 1, 0), $this->noteUnlinked($matter, $kept), $userId);
    }

    /** A held email that is no matter's: set aside, its files gone — and its copies waiting in other mailboxes' tabs. */
    public function dismiss(MatterEmail $email): void
    {
        $this->setAside($email);
        $this->setAsideCopies($email);
    }

    private function setAside(MatterEmail $email): void
    {
        Documents::delete(array_values(array_filter(array_map(fn ($file) => $file['path'] ?? null, (array) ($email->files ?? [])))));
        $email->update(['status' => MatterEmail::DISMISSED, 'files' => []]);
        $this->answered($email);
    }

    /**
     * The other copies of an email filed or set aside — it came to more than one of our mailboxes, each kept
     * apart (MatterEmail::copiesOf()) — set aside with it: done once, not left waiting in the other tab.
     */
    private function setAsideCopies(MatterEmail $email): void
    {
        $email->copiesOf()->each(fn (MatterEmail $copy) => $this->setAside($copy));
    }

    /**
     * A received email filed on the wrong matter, moved to another: its files kept in the system go
     * with the matter's attachments, those in OneDrive to the other matter's received-emails folder
     * (the PDF saved there when it was not in OneDrive), and its step in the progress follows.
     *
     * @return array{pdf: bool, eml: bool, attachments: int, folder: string, moved: bool}
     */
    public function move(MatterEmail $email, Matter $to, ?int $userId = null): array
    {
        $from = $email->matter;
        abort_if($email->direction !== MatterEmail::RECEIVED || ! $from || $from->is($to), 422);

        $folders = app(MatterOneDriveFolders::class);
        $oneDrive = $folders->moveEmailFiles($email, $from, $to);

        // The files kept in the system: with the other matter's attachments.
        $paths = array_values(array_filter(array_map(fn ($file) => $file['path'] ?? null, (array) ($email->files ?? []))));
        if ($paths !== []) {
            $from->attachments()->whereIn('path', $paths)->update(['matter_id' => $to->getKey()]);
        }

        // Its PDF not in OneDrive (or not found there): saved in the other matter's folder from the system.
        $pdf = (string) ($email->files[0]['path'] ?? '');
        if (! $oneDrive['pdf'] && $disk = Documents::storage($pdf)) {
            $oneDrive['pdf'] = $this->archive->keepInOneDrive($to, (string) $email->files[0]['name'], (string) $disk->get($pdf), 'application/pdf', $oneDrive['folder']);
        }

        $email->update([
            'matter_id' => $to->getKey(),
            'matched_by' => MatterEmail::BY_HAND,
            'onedrive' => array_filter(['pdf' => $oneDrive['pdf'], 'eml' => $oneDrive['eml'], 'attachments' => $oneDrive['attachments'], 'folder' => $oneDrive['folder'], 'attachments_folder' => $oneDrive['attachments_folder'] ?? null], fn ($value): bool => $value !== null),
        ]);

        // Its step in the progress, on the other matter.
        MatterProgress::query()->where('source_type', $email->getMorphClass())->where('source_id', $email->getKey())->update(['matter_id' => $to->getKey()]);
        if ($email->parent_id === null) {
            MatterProgressRecorder::emailReceived($email, $userId);
        }

        MatterContact::learn($email);

        // The other matter's people hear of it, as of an email filed there — who moved it, already read.
        $this->tellMatter($email, $to, max(count((array) $email->files) - 1, 0), [], $userId);

        return $oneDrive;
    }

    /**
     * The received email's record.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $attributes
     */
    private function record(array $message, ?string $id, string $senderKey, array $attributes): MatterEmail
    {
        $from = trim((string) ($message['from'] ?? ''));
        $fromName = trim((string) ($message['from_name'] ?? '')) ?: $from;
        $body = $message['body'] ?? '';
        $files = $message['attachments'] ?? [];
        // Its text, kept — for a reply to quote.
        $text = $body instanceof Closure ? (string) $body() : (string) $body;

        return MatterEmail::create([
            'direction' => MatterEmail::RECEIVED,
            'sender_key' => $senderKey,
            'message_id' => $id,
            'subject' => mb_substr((string) ($message['subject'] ?? ''), 0, 500),
            'from' => mb_substr(trim($fromName.($fromName !== $from ? ' <'.$from.'>' : '')), 0, 255),
            'to' => array_values(array_filter((array) ($message['to'] ?? []))),
            'cc' => array_values(array_filter((array) ($message['cc'] ?? []))),
            // Its words and files, to tell a copy of it that came to another of our mailboxes (MatterEmail::copiesOf()).
            'body' => $text,
            'content_hash' => MatterEmail::fingerprint($text, (array) ($files instanceof Closure ? $files() : $files)),
            'at' => self::arrivedAt($message),
            // Unread in the mailbox before it was collected (null: not known).
            'was_unread' => isset($message['was_read']) ? ! $message['was_read'] : null,
            ...$attributes,
        ]);
    }

    /**
     * A received email as a PDF, the files it came with, and its name —
     * for the party who sent it, by the pattern of their side on its matter (ReceivedEmailFiling).
     *
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: list<array{name: string, contents: string, mime?: string}>, 2: string, 3: ?string, 4: list<array{url: string, label: string}>, 5: string, 6: string} the PDF, the files, the name, the original email (.eml) when the mailbox gives it, the links in it to documents to download, the side of the matter it came from, and the address it came from
     */
    private function rendered(array $message, string $senderKey, bool $reply, ?Matter $matter = null, bool $received = true): array
    {
        $body = $message['body'] instanceof Closure ? ($message['body'])() : (string) ($message['body'] ?? '');
        $files = $message['attachments'] instanceof Closure ? ($message['attachments'])() : (array) ($message['attachments'] ?? []);
        $at = self::arrivedAt($message);
        $from = trim((string) ($message['from'] ?? ''));
        $fromName = trim((string) ($message['from_name'] ?? '')) ?: $from;

        $pdf = EmailPdf::render(
            (string) ($message['subject'] ?? ''),
            $body,
            ['name' => $fromName, 'address' => $from],
            '',
            array_values((array) ($message['to'] ?? [])),
            array_values((array) ($message['cc'] ?? [])),
            [],
            array_map(fn (array $file): array => ['name' => (string) $file['name'], 'size' => strlen((string) $file['contents'])], $files),
            $at,
            // The mailbox it came to, as Outlook prints "Mail - … ".
            // One put in OneDrive by hand came to no mailbox of ours: its sender's.
            SenderMailer::all()[$senderKey]['name'] ?? ($senderKey === MatterEmail::BY_ONEDRIVE ? null : $senderKey),
            received: $received,
        );

        $side = $matter ? ReceivedEmailFiling::side($matter, $from) : ReceivedEmailFiling::GENERAL;
        $name = ReceivedEmailFiling::name($matter, $side, self::partyName($from) ?? $fromName, (string) ($message['subject'] ?? ''), $at, $reply, $from);

        return [$pdf, $files, $name, self::original($message), EmailDownloadLinks::in($body), $side, $from, self::partyName($from) ?? $fromName];
    }

    /**
     * On the matter: the PDF in its received-emails folder, the files it
     * came with in a folder of the same name beside it. Where each went.
     *
     * @param  list<array{name: string, contents: string, mime?: string}>  $files
     * @param  ?string  $source  the original email (.eml), kept beside the PDF
     * @param  array<string, mixed>|null  $oneDrive  set to what reached the matter's OneDrive: {pdf, eml, attachments}
     * @return list<array{name: string, path: string}>
     */
    private function keepOnMatter(Matter $matter, string $pdf, array $files, string $name, ?int $userId, ?string $source = null, &$oneDrive = null, ?CarbonInterface $at = null, string $side = ReceivedEmailFiling::GENERAL, string $from = '', string $party = ''): array
    {
        // Its files apart, when the side has an attachments folder (a party's documents folder).
        $attachmentsFolder = $files !== [] ? MatterOneDriveFolders::attachmentsFolderFor($matter, $at, $side, $from, $party !== '' ? $party : $from) : '';

        $kept = $this->keepIn($matter, MatterOneDriveFolders::receivedEmailsFolderFor($matter, $at, $side, $from), $pdf, $files, $name, $userId, $source, $oneDrive, $attachmentsFolder);
        $this->keepCopies($matter, $pdf, $files, $name, $source, $at, $from, $side, $party, $oneDrive);

        return $kept;
    }

    /**
     * An email from someone with more than one capacity on the matter (a party that is a creditor too): a copy of
     * it — its PDF, its original and the files it came with — in each other capacity's folders as well, in OneDrive
     * only, and where each went noted with it (onedrive.copies). The matter's attachments, its progress and the
     * email's own folder stay the one.
     *
     * @param  list<array{name: string, contents: string, mime?: string}>  $files
     * @param  array<string, mixed>  $oneDrive
     */
    private function keepCopies(Matter $matter, string $pdf, array $files, string $name, ?string $source, ?CarbonInterface $at, string $from, string $side, string $party, array &$oneDrive): void
    {
        foreach (array_diff(ReceivedEmailFiling::sidesOf($matter, $from), [$side]) as $other) {
            $folder = MatterOneDriveFolders::receivedEmailsFolderFor($matter, $at, $other, $from);

            if ($folder === '') {
                continue;
            }

            $attachmentsFolder = $files !== [] ? MatterOneDriveFolders::attachmentsFolderFor($matter, $at, $other, $from, $party !== '' ? $party : $from) : '';
            $copy = [
                'side' => $other,
                'folder' => $folder,
                'attachments_folder' => $attachmentsFolder !== '' ? $attachmentsFolder : null,
                'pdf' => $this->archive->keepInOneDrive($matter, $name.'.pdf', $pdf, 'application/pdf', $folder),
                'eml' => $source !== null && $this->archive->keepInOneDrive($matter, $name.'.eml', $source, 'message/rfc822', $folder),
                'attachments' => 0,
            ];

            foreach ($files as $file) {
                $copy['attachments'] += $this->archive->keepInOneDrive($matter, SentEmailArchive::shortFileName((string) $file['name']), (string) $file['contents'], (string) ($file['mime'] ?? 'application/octet-stream'), $attachmentsFolder !== '' ? $attachmentsFolder : $folder.'/'.$name) ? 1 : 0;
            }

            $oneDrive['copies'][] = array_filter($copy, fn ($value): bool => $value !== null);
        }
    }

    /**
     * An email put in the matter's OneDrive folder by hand (.eml, .msg — OneDriveAddedEmails), on the matter: left
     * where it was put, its PDF and the files it came with beside it under its name; received — or sent, from one
     * of our addresses — a step in its progress, its people told of one received, its contacts learned.
     *
     * @param  array<string, mixed>  $message
     * @param  string  $folder  where it was put, in the matter's folder
     * @param  string  $file  its file's name there
     */
    public function keepAdded(Matter $matter, array $message, string $id, string $folder, string $file, ?int $userId = null): MatterEmail
    {
        $sent = MatterContact::isOurs((string) ($message['from'] ?? ''));
        // Sent from one of our mailboxes: its replies looked for there.
        $mailbox = $sent ? collect(SenderMailer::all())->search(fn (array $sender): bool => mb_strtolower((string) ($sender['address'] ?? '')) === mb_strtolower((string) $message['from'])) : false;
        $email = $this->record($message, $id, $mailbox !== false ? (string) $mailbox : MatterEmail::BY_ONEDRIVE, [
            'matter_id' => $matter->getKey(),
            'direction' => $sent ? MatterEmail::SENT : MatterEmail::RECEIVED,
            'status' => MatterEmail::FILED,
            'matched_by' => MatterEmail::BY_ONEDRIVE,
            'user_id' => $userId,
        ]);
        [$pdf, $files, , , $links] = $this->rendered($message, MatterEmail::BY_ONEDRIVE, false, $matter, received: ! $sent);
        $name = pathinfo($file, PATHINFO_FILENAME);

        $kept = $this->keepIn($matter, $folder, $pdf, $files, $name, $userId, null, $oneDrive);
        $email->update(['files' => $kept, 'links' => $links, 'onedrive' => [...$oneDrive, 'added' => $file]]);

        if ($sent) {
            MatterProgressRecorder::emailSentByHand($email, $userId);
            MatterContact::learnSent($email);
        } else {
            MatterProgressRecorder::emailReceived($email, $userId);
            MatterContact::learn($email);
            $this->tellMatter($email, $matter, count($files), [], $userId, filerReads: false);
        }

        return $email;
    }

    /**
     * On the matter, in this folder of its OneDrive: the PDF, the files it came with in a folder of the same name
     * beside it. Where each went.
     *
     * @param  list<array{name: string, contents: string, mime?: string}>  $files
     * @param  array<string, mixed>|null  $oneDrive
     * @return list<array{name: string, path: string}>
     */
    private function keepIn(Matter $matter, string $folder, string $pdf, array $files, string $name, ?int $userId, ?string $source, &$oneDrive, string $attachmentsFolder = ''): array
    {
        // The folder it goes in is kept with it, to say where it is — and its files' own folder, when they go apart.
        $oneDrive = ['pdf' => false, 'eml' => false, 'attachments' => 0, 'folder' => $folder, ...($attachmentsFolder !== '' ? ['attachments_folder' => $attachmentsFolder] : [])];
        $kept = [['name' => $name.'.pdf', 'path' => $this->archive->keepFile($matter, $name.'.pdf', $pdf, 'application/pdf', $folder, $userId, inOneDrive: $oneDrive['pdf'])]];

        foreach ($files as $file) {
            $attachment = $this->keepAttachment($matter, (string) $file['name'], (string) $file['contents'], (string) ($file['mime'] ?? 'application/octet-stream'), $attachmentsFolder !== '' ? $attachmentsFolder : ($folder !== '' ? $folder.'/'.$name : ''), $userId);
            $kept[] = ['name' => $attachment['name'], 'path' => $attachment['path']];
            $oneDrive['attachments'] += $attachment['saved'] ? 1 : 0;
        }

        // The original email goes to OneDrive only, beside the PDF — nowhere in the system.
        if ($source !== null) {
            $oneDrive['eml'] = $this->archive->keepInOneDrive($matter, $name.'.eml', $source, 'message/rfc822', $folder);
        }

        // Every file is listed — the PDF first — even one kept in OneDrive only (no path here).
        return $kept;
    }

    /**
     * A file an email came with: in the matter's OneDrive only — not with the matter's attachments,
     * not in the system. Only when OneDrive cannot take it (the matter has no folder there, no folder
     * is set for received emails, OneDrive refused) is it kept in the system, so it is never lost.
     *
     * @return array{name: string, path: ?string, saved: bool} saved: in OneDrive; path: where it is kept in the system, if it is
     */
    private function keepAttachment(Matter $matter, string $name, string $contents, string $mime, string $subfolder, ?int $userId): array
    {
        // Under a name short enough for a OneDrive synced to a PC (SentEmailArchive::FILE_LIMIT), kept as it is listed.
        $name = SentEmailArchive::shortFileName($name);
        $saved = trim($subfolder) !== '' && $this->archive->keepInOneDrive($matter, $name, $contents, $mime, $subfolder);

        return [
            'name' => $name,
            'path' => $saved ? null : $this->archive->keepFile($matter, $name, $contents, $mime, '', $userId),
            'saved' => $saved,
        ];
    }

    /**
     * Of no matter (yet): the PDF and its files in a folder of their own.
     *
     * @param  list<array{name: string, contents: string, mime?: string}>  $files
     * @return list<array{name: string, path: string}>
     */
    private function keepLoose(string $folder, string $pdf, array $files, string $name, ?string $source = null): array
    {
        $kept = [['name' => $name.'.pdf', 'path' => $this->archive->keepLoose($folder, $name.'.pdf', $pdf)]];

        foreach ($files as $file) {
            $kept[] = ['name' => (string) $file['name'], 'path' => $this->archive->keepLoose($folder.'/'.$name, (string) $file['name'], (string) $file['contents'])];
        }

        if ($source !== null) {
            $kept[] = ['kind' => MatterEmail::SOURCE, 'name' => $name.'.eml', 'path' => $this->archive->keepLoose($folder, $name.'.eml', $source)];
        }

        return $kept;
    }

    /** When a message reached the mailbox, in the app's timezone — the day it is saved under. */
    private static function arrivedAt(array $message): CarbonInterface
    {
        return (isset($message['at']) ? Carbon::parse($message['at']) : now())->setTimezone(config('app.timezone'));
    }

    /**
     * The email exactly as it came (RFC 822, the .eml an email program opens) —
     * null when the mailbox does not give it, or it is too big to keep twice.
     *
     * @param  array<string, mixed>  $message
     */
    private static function original(array $message): ?string
    {
        if (! ($message['raw'] ?? null) instanceof Closure) {
            return null;
        }

        try {
            $raw = (string) ($message['raw'])();
        } catch (Throwable $e) {
            Log::info('Original email not read: '.$e->getMessage(), ['message' => $message['message_id'] ?? null]);

            return null;
        }

        return $raw !== '' && strlen($raw) <= self::MAX_ORIGINAL_BYTES ? $raw : null;
    }

    /**
     * Whether a part of an email is only a picture of its text — a signature's logo — and not a file that came with
     * it: a picture the text shows by its Content-ID ("cid:…"), however the mail program marked it (Outlook sends
     * a reply's signature pictures as attachments, not "inline"); one marked inline with no Content-ID at all (it
     * can only be decoration); or one Outlook named itself (image001.png, Outlook-ab12cd.png), small as a
     * signature's picture is. Anything else — a PDF Apple Mail or an iPhone sent "inline", a photo attached — is
     * a file.
     */
    public static function isPartOfText(string $name, string $mime, string $contentId, int $bytes, bool $inline, string $html): bool
    {
        $name = trim($name);

        if (! str_starts_with(strtolower($mime), 'image/') && preg_match('/\.(?:png|jpe?g|gif|bmp)$/i', $name) !== 1) {
            return false;
        }

        $contentId = trim($contentId, " <>\t\r\n");

        return ($contentId !== '' && stripos($html, 'cid:'.$contentId) !== false)
            || ($inline && $contentId === '')
            || (preg_match(self::OUTLOOK_PICTURE, $name) === 1 && $bytes <= self::SIGNATURE_PICTURE_BYTES);
    }

    /** A part of an email read over IMAP (or carried in a forward): only a picture of its text (isPartOfText()). */
    public static function isPartOfTextPart(ImapAttachment $part, string $html): bool
    {
        return self::isPartOfText(
            SentMailImporter::decodeHeader((string) ($part->name ?: $part->filename)),
            (string) $part->content_type,
            (string) $part->id,
            strlen((string) $part->content),
            $part->disposition === 'inline',
            $html,
        );
    }

    /** A message's body or files, read the first time asked for only. */
    private static function once(mixed $value): mixed
    {
        if (! $value instanceof Closure) {
            return $value;
        }

        $read = false;
        $result = null;

        return function () use ($value, &$read, &$result) {
            if (! $read) {
                $result = $value();
                $read = true;
            }

            return $result;
        };
    }

    /** The party with this email address, by name — the first one. */
    public static function partyName(string $address): ?string
    {
        $address = mb_strtolower(trim($address));

        if ($address === '') {
            return null;
        }

        return Party::query()
            // Narrowed by the database, then exactly: a "_" in an address is a wildcard there.
            ->where('email', 'like', '%'.$address.'%')
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->first(fn (Party $party) => in_array($address, array_map('mb_strtolower', (array) $party->email), true))
            ?->name;
    }

    /** Where to see a reply: its matter, or the campaign its email was of. */
    private static function replyUrl(MatterEmail $reply, MatterEmail $answers): ?string
    {
        if ($reply->matter_id) {
            return MatterResource::getUrl('view', ['record' => $reply->matter_id], panel: 'mms');
        }

        $recipient = $answers->source;

        return $recipient instanceof BulkMailRecipient
            ? BulkMailCampaignResource::getUrl('view', ['record' => $recipient->campaign_id], panel: 'mms')
            : null;
    }

    /**
     * Whoever sent the email: told of the reply — and, the reply kept on a matter, its people too, as of any
     * email filed there (its expert and assistant, the super admins).
     */
    private function tell(MatterEmail $reply, MatterEmail $answers, int $files): void
    {
        $users = collect([$answers->user])->filter();
        if ($reply->matter_id) {
            $users = $users->merge(self::matterUsers([(int) $reply->matter_id]))->unique('id')->values();
        }
        if ($users->isEmpty()) {
            return;
        }

        try {
            Notifier::send(
                NotificationType::MATTER_REPLY_RECEIVED,
                $users,
                $reply->matter
                    ? __(':name replied on matter :matter', ['name' => $reply->from, 'matter' => $reply->matter->year.'/'.$reply->matter->number])
                    : __(':name replied to a bulk email', ['name' => $reply->from]),
                trim($reply->subject.($files ? ' — '.trans_choice(':count file|:count files', $files, ['count' => $files]) : '')),
                self::replyUrl($reply, $answers),
                fn (Notification $notification) => $notification->success()->icon('heroicon-o-envelope-open')->iconColor('success')
                    ->viewData([NotificationType::DATA_KEY => NotificationType::MATTER_REPLY_RECEIVED->value, self::EMAIL_KEY => $reply->getKey()]),
            );
        } catch (Throwable $e) {
            Log::info('Reply notification not sent: '.$e->getMessage());
        }
    }

    /**
     * The people of these matters — the users of their experts and assistants, the assistants'
     * administrators — and the super admins, who hear of every email that arrives too.
     *
     * @param  list<int>  $matterIds
     * @return Collection<int, User>
     */
    private static function matterUsers(array $matterIds): Collection
    {
        return MatterPeople::of($matterIds, superAdmins: true);
    }

    /**
     * The matter's people: told of a party's email kept on it. Whoever filed it by hand (or moved it) has it in
     * their bell too — its Emails tab — already read, and is sent no email or message of what they did.
     *
     * @param  list<array{name: string, url: string}>  $downloads  when the matter has no OneDrive folder: the email's files, to download and put there by hand
     * @param  int|null  $filedBy  whoever filed or moved it by hand
     */
    private function tellMatter(MatterEmail $email, Matter $matter, int $files, array $downloads = [], ?int $filedBy = null, bool $filerReads = true): void
    {
        $notInOneDrive = $downloads !== [];
        $downloads = array_values(array_filter($downloads, fn (array $file) => filled($file['url'])));
        $filer = $filedBy !== null ? User::find($filedBy) : null;
        $users = self::matterUsers([(int) $matter->getKey()])->reject(fn (User $user): bool => $user->getKey() === $filedBy);
        if ($users->isEmpty() && ! $filer) {
            return;
        }

        // What was kept, one point each.
        $points = [
            trim($email->subject),
            $files ? trans_choice(':count file|:count files', $files, ['count' => $files]) : null,
            $notInOneDrive ? __('Not saved in OneDrive: this matter is not linked to a OneDrive folder, so the email and its files are kept in the system only.') : null,
        ];
        $url = MatterResource::getUrl('view', ['record' => $matter], panel: 'mms');
        $type = NotificationType::MATTER_EMAIL_RECEIVED;
        $title = __(':name emailed on matter :matter', ['name' => $email->from, 'matter' => $matter->year.'/'.$matter->number]);
        $configure = fn (Notification $notification) => $notification
            ->success()
            ->icon('heroicon-o-inbox-arrow-down')
            ->iconColor('success')
            ->body(self::bullets($points))
            ->viewData([NotificationType::DATA_KEY => $type->value, self::EMAIL_KEY => $email->getKey()])
            ->actions([
                Action::make('view')->label(__('View'))->url($url)->markAsRead(),
                // No folder to find it in: the email, to download and keep by hand.
                ...($downloads !== [] ? [Action::make('download')->label(__('Download the email'))->icon('heroicon-o-arrow-down-tray')->url($downloads[0]['url'], shouldOpenInNewTab: true)] : []),
            ]);

        try {
            // The email and the message take it as plain lines; the bell shows the bullets.
            Notifier::send($type, $users, $title, collect($points)->filter()->implode("\n"), $url, $configure);

            if ($filer) {
                Notifier::bell($type, collect([$filer]), $title, null, $url, $configure);

                if ($filerReads) {
                    $filer->unreadNotifications()->where('data->viewData->'.self::EMAIL_KEY, $email->getKey())->update(['read_at' => now()]);
                }
            }
        } catch (Throwable $e) {
            Log::info('Email notification not sent: '.$e->getMessage());
        }
    }

    /** Filed or set aside: "which matter is it about?" answered, for everyone asked. */
    private function answered(MatterEmail $email): void
    {
        DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('data->viewData->'.NotificationType::DATA_KEY, NotificationType::MATTER_EMAIL_UNFILED->value)
            ->where('data->viewData->'.self::EMAIL_KEY, $email->getKey())
            ->update(['read_at' => now()]);
    }

    /**
     * Points as a bullet list (HTML), the empty ones left out.
     *
     * @param  list<string|HtmlString|null>  $points  text, or HTML already made
     */
    public static function bullets(array $points): HtmlString
    {
        return new HtmlString('<ul style="list-style: disc; padding-inline-start: 1.1rem; margin: 0;">'
            .collect($points)->filter(fn ($point) => filled((string) $point))->map(fn ($point) => '<li>'.($point instanceof HtmlString ? $point->toHtml() : e($point)).'</li>')->implode('')
            .'</ul>');
    }

    /** The people of each matter it may be about: asked to choose. */
    private function tellUnfiled(MatterEmail $email): void
    {
        $candidates = array_map('intval', (array) $email->candidates);
        // In someone's own mailbox: theirs alone to choose.
        $owner = SenderMailer::ownerOf($email->sender_key);
        $users = $owner !== null ? User::query()->whereKey($owner)->get() : self::matterUsers($candidates);
        if ($users->isEmpty()) {
            return;
        }

        $matters = Matter::query()->whereKey($candidates)->get(['id', 'year', 'number'])->map(fn (Matter $m) => $m->year.'/'.$m->number)->all();

        $url = UnfiledEmails::getUrl(panel: 'mms');

        try {
            Notifier::send(
                NotificationType::MATTER_EMAIL_UNFILED,
                $users,
                __('Which matter is this email from :name about?', ['name' => $email->from]),
                collect([trim($email->subject), $matters === [] ? null : __('It may be about:').' '.implode('، ', $matters)])->filter()->implode("\n"),
                $url,
                fn (Notification $notification) => $notification
                    ->warning()
                    ->icon('heroicon-o-inbox-stack')
                    ->iconColor('warning')
                    // The subject, then the matters it may be about, one point each (none: a sender on no matter).
                    ->body(self::bullets($matters === [] ? [trim($email->subject)] : [trim($email->subject), new HtmlString(e(__('It may be about:')).self::bullets($matters)->toHtml())]))
                    // Which email: read for everyone once it is filed or set aside.
                    ->viewData([NotificationType::DATA_KEY => NotificationType::MATTER_EMAIL_UNFILED->value, self::EMAIL_KEY => $email->getKey()])
                    ->actions([
                        Action::make('choose')->label(__('Choose its matter'))->url($url)->markAsRead(),
                    ]),
            );
        } catch (Throwable $e) {
            Log::info('Unfiled email notification not sent: '.$e->getMessage());
        }
    }

    /**
     * New messages in a cPanel mailbox's inbox: headers first, a message's
     * body and files read only once it's a reply.
     *
     * @param  array<string, mixed>  $sender
     * @return iterable<array<string, mixed>>
     */
    private function fromImap(string $senderKey, array $sender, CarbonInterface $since): iterable
    {
        $inbox = $this->mailboxes->inbox($senderKey, $sender);
        // A day earlier than the time asked for: IMAP's SINCE is a calendar date, compared with each
        // message's date in the mail server's own time zone (US time on cPanel hosts) — from the
        // app's day, the mail delivered in the hours between the two would never be read. Messages
        // read already are skipped by their Message-ID.
        // A page of headers at a time, oldest first: a long period is never loaded whole, and a run out of
        // time stops on the page it is on (the next goes on from the last message handled).
        for ($page = 1; ; $page++) {
            // Flags too: whether each was read before it was collected (kept, so an undo puts it back).
            $query = $inbox->query()->whereSince($since->copy()->subDay()->startOfDay())->leaveUnread()->setFetchBody(false)->setFetchFlags(true)
                ->setFetchOrderAsc()->limit(self::IMAP_PAGE, $page);
            $headers = $query->get();

            // Messages the library could not read (it skips them rather than failing the mailbox): said in the log.
            foreach ($query->errors() as $uid => $error) {
                Log::warning('Email could not be read from '.$senderKey, ['uid' => $uid, 'error' => $error instanceof Throwable ? $error->getMessage() : (string) $error]);
            }

            yield from $this->imapPage($inbox, $headers, $since);

            if ($headers->count() < self::IMAP_PAGE) {
                return;
            }
        }
    }

    /**
     * One page of a cPanel inbox's headers as messages: a message's body and files read only once it's
     * kept.
     *
     * @return iterable<array<string, mixed>>
     */
    private function imapPage(Folder $inbox, iterable $headers, CarbonInterface $since): iterable
    {
        foreach ($headers as $header) {
            // The whole day before was asked for (SINCE is a date): none from before the time asked.
            $date = $header->getDate()->toDate();
            if ($date->year >= 1971 && $date->lt($since)) {
                continue;
            }

            $from = collect($header->get('from')?->all() ?? [])->first(fn ($a) => $a instanceof Address);
            $uid = (int) $header->uid;
            // Decoded tolerantly: a badly encoded part ("iconv(): Detected an illegal character") must not lose the email.
            $whole = self::once(fn () => Tolerant::run(fn () => $inbox->query()->leaveUnread()->setFetchFlags(false)->getMessageByUid($uid)));

            yield [
                'message_id' => (string) $header->getMessageId(),
                'references' => [...(array) ($header->get('in_reply_to')?->all() ?? []), ...(array) ($header->get('references')?->all() ?? [])],
                'subject' => SentMailImporter::decodeHeader((string) $header->getSubject()),
                'from' => (string) ($from?->mail ?? ''),
                'from_name' => SentMailImporter::decodeHeader((string) ($from?->personal ?? '')),
                'to' => self::imapAddresses($header, 'to'),
                'cc' => self::imapAddresses($header, 'cc'),
                // The moment with its offset — "+0000" from Outlook is not the app's local time.
                // (An unreadable Date header comes back as 1970: read as now instead.)
                'at' => $date->year < 1971 ? now()->toIso8601String() : $date->toIso8601String(),
                'was_read' => $header->hasFlag('seen'),
                'headers' => fn (): array => self::headerLines((string) ($header->getHeader()?->raw ?? '')),
                // The whole message, fetched once however many of these are asked for.
                'body' => fn (): string => Tolerant::run(function () use ($whole): string {
                    $message = $whole();

                    return $message->hasHTMLBody() ? $message->getHTMLBody() : nl2br(e($message->getTextBody()));
                }),
                'attachments' => fn (): array => Tolerant::run(function () use ($whole): array {
                    $message = $whole();
                    $files = [];

                    $html = $message->hasHTMLBody() ? (string) $message->getHTMLBody() : '';

                    foreach ($message->getAttachments() as $attachment) {
                        // Not a picture of the email's text (a signature's logo) — but a PDF that Apple
                        // Mail or Outlook sent "inline" is a file it came with.
                        if (self::isPartOfTextPart($attachment, $html)) {
                            continue;
                        }

                        $files[] = [
                            'name' => SentMailImporter::decodeHeader((string) ($attachment->name ?: $attachment->filename)) ?: 'attachment',
                            'contents' => (string) $attachment->content,
                            'mime' => (string) ($attachment->content_type ?: 'application/octet-stream'),
                        ];
                    }

                    return $files;
                }),
                // The email as it came: its header, a blank line, its body.
                'raw' => function () use ($whole): string {
                    $message = $whole();

                    return rtrim((string) $message->getHeader()?->raw, "\r\n")."\r\n\r\n".$message->getRawBody();
                },
                // Seen in the mailbox, like one opened there.
                'mark_read' => function () use ($inbox, $uid): void {
                    $inbox->query()->leaveUnread()->setFetchBody(false)->setFetchFlags(false)->getMessageByUid($uid)->setFlag('Seen');
                },
            ];
        }
    }

    /**
     * An email's header lines, by name (lower case): a name given more than once, each value on a line of its own,
     * the topmost first — the one the receiving server added, above any the sender wrote (ForwardedEmailFiler).
     *
     * @return array<string, string>
     */
    private static function headerLines(string $raw): array
    {
        $lines = [];

        foreach (explode("\n", preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $raw)) ?? '') as $line) {
            if (preg_match('/^([^:\s]+):\s*(.*)$/', $line, $m)) {
                $name = mb_strtolower($m[1]);
                $lines[$name] = isset($lines[$name]) ? $lines[$name]."\n".trim($m[2]) : trim($m[2]);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function imapAddresses(Message $message, string $header): array
    {
        return array_values(array_map(fn (Address $a): string => $a->mail, array_filter($message->get($header)?->all() ?? [], fn ($a) => $a instanceof Address)));
    }

    /**
     * New messages in a Microsoft 365 mailbox — in every folder of it (the Inbox, and any folder a rule or a
     * person moved an email to) but what it sent or is writing, junk and the bin.
     *
     * @return iterable<array<string, mixed>>
     */
    private function fromGraph(string $mailbox, CarbonInterface $since): iterable
    {
        $base = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox);
        $notRead = $this->graphFoldersNotRead($base, $mailbox);
        $url = $base.'/messages';
        $query = [
            '$filter' => 'receivedDateTime ge '.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$select' => self::GRAPH_FIELDS.',parentFolderId',
            '$top' => 50,
            // Oldest first: a run that stops for time goes on from the last one handled.
            '$orderby' => 'receivedDateTime asc',
        ];

        while ($url !== null) {
            $response = Http::withToken($this->graph->getAccessToken())->timeout(60)->get($url, $query);

            if ($response->status() === 403) {
                throw new RuntimeException(__('Microsoft 365 refused to read :mailbox: the app registration needs the Mail.Read application permission, with admin consent.', ['mailbox' => $mailbox]));
            }

            if ($response->failed()) {
                throw new RuntimeException('Microsoft Graph '.$response->status().': '.($response->json('error.message') ?? $response->body()));
            }

            $body = $response->json();

            foreach ($body['value'] ?? [] as $item) {
                if (in_array((string) ($item['parentFolderId'] ?? ''), $notRead, true)) {
                    continue;
                }

                yield $this->graphMessage($base, $mailbox, $item);
            }

            // The next link carries the query itself.
            $url = $body['@odata.nextLink'] ?? null;
            $query = null;
        }
    }

    /**
     * The ids of the Microsoft 365 folders never read (GRAPH_FOLDERS_NOT_READ) — asked for once a day a mailbox,
     * when every one answered; a folder the mailbox has not is left out.
     *
     * @return list<string>
     */
    private function graphFoldersNotRead(string $base, string $mailbox): array
    {
        $key = 'graph-folders-not-read:'.mb_strtolower($mailbox);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $ids = [];
        $answered = true;

        foreach (self::GRAPH_FOLDERS_NOT_READ as $folder) {
            $response = Http::withToken($this->graph->getAccessToken())->timeout(30)->get($base.'/mailFolders/'.$folder, ['$select' => 'id']);

            if ($response->successful() && is_string($id = $response->json('id')) && $id !== '') {
                $ids[] = $id;
            } elseif ($response->status() !== 404) {
                $answered = false;
            }
        }

        if ($answered) {
            Cache::put($key, $ids, now()->addDay());
        }

        return $ids;
    }

    /**
     * A Microsoft 365 message as the collector reads one: its body now; its original, files and "read"
     * only when asked for.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function graphMessage(string $base, string $mailbox, array $item): array
    {
        // As headerLines(): a name given more than once, each value on a line of its own, the topmost first.
        $headers = collect($item['internetMessageHeaders'] ?? [])
            ->groupBy(fn ($h) => mb_strtolower((string) ($h['name'] ?? '')))
            ->map(fn ($values) => $values->pluck('value')->map(fn ($value) => (string) $value)->implode("\n"));
        $graphId = (string) $item['id'];

        return [
            'message_id' => (string) ($item['internetMessageId'] ?? ''),
            'references' => preg_split('/\s+/', trim($headers->get('in-reply-to', '').' '.$headers->get('references', ''))) ?: [],
            'subject' => (string) ($item['subject'] ?? ''),
            'from' => (string) ($item['from']['emailAddress']['address'] ?? ''),
            'from_name' => (string) ($item['from']['emailAddress']['name'] ?? ''),
            'to' => array_values(array_filter(array_map(fn ($r) => $r['emailAddress']['address'] ?? null, $item['toRecipients'] ?? []))),
            'cc' => array_values(array_filter(array_map(fn ($r) => $r['emailAddress']['address'] ?? null, $item['ccRecipients'] ?? []))),
            'at' => (string) ($item['receivedDateTime'] ?? now()->toIso8601String()),
            'headers' => $headers->all(),
            'was_read' => isset($item['isRead']) ? (bool) $item['isRead'] : null,
            'body' => ($item['body']['contentType'] ?? 'html') === 'html' ? (string) ($item['body']['content'] ?? '') : nl2br(e((string) ($item['body']['content'] ?? ''))),
            // The email as it came (MIME).
            'raw' => function () use ($base, $graphId): string {
                $response = Http::withToken($this->graph->getAccessToken())->timeout(120)->get($base.'/messages/'.rawurlencode($graphId).'/$value');

                return $response->successful() ? $response->body() : '';
            },
            // Read in the mailbox too — needs the Mail.ReadWrite application permission.
            'mark_read' => function () use ($base, $graphId, $mailbox): void {
                $response = Http::withToken($this->graph->getAccessToken())->timeout(20)->patch($base.'/messages/'.rawurlencode($graphId), ['isRead' => true]);

                if ($response->status() === 403) {
                    throw new RuntimeException(__('Microsoft 365 refused to mark emails as read in :mailbox: the app registration needs the Mail.ReadWrite application permission, with admin consent.', ['mailbox' => $mailbox]));
                }
            },
            'attachments' => function () use ($base, $graphId, $item): array {
                if (! ($item['hasAttachments'] ?? false)) {
                    return [];
                }

                $response = Http::withToken($this->graph->getAccessToken())->timeout(120)->get($base.'/messages/'.rawurlencode($graphId).'/attachments');

                return collect($response->successful() ? ($response->json()['value'] ?? []) : [])
                    ->filter(fn ($a) => isset($a['contentBytes']) || ($a['@odata.type'] ?? '') === '#microsoft.graph.itemAttachment')
                    ->reject(fn ($a) => self::isPartOfText((string) ($a['name'] ?? ''), (string) ($a['contentType'] ?? ''), (string) ($a['contentId'] ?? ''), (int) ($a['size'] ?? strlen((string) base64_decode((string) ($a['contentBytes'] ?? '')))), (bool) ($a['isInline'] ?? false), (string) ($item['body']['content'] ?? '')))
                    ->map(fn ($a): ?array => isset($a['contentBytes'])
                        ? ['name' => (string) ($a['name'] ?? 'attachment'), 'contents' => (string) base64_decode($a['contentBytes']), 'mime' => (string) ($a['contentType'] ?? 'application/octet-stream')]
                        // An email attached to the email (forwarded as an attachment): no bytes in the list — the
                        // email itself (MIME) asked for, kept as the .eml it is.
                        : $this->attachedEmail($base, $graphId, $a))
                    ->filter()
                    ->values()
                    ->all();
            },
        ];
    }

    /**
     * An email attached to a Microsoft 365 email, as the .eml it is — null when it cannot be read.
     *
     * @param  array<string, mixed>  $attachment
     * @return array{name: string, contents: string, mime: string}|null
     */
    private function attachedEmail(string $base, string $graphId, array $attachment): ?array
    {
        $response = Http::withToken($this->graph->getAccessToken())->timeout(120)
            ->get($base.'/messages/'.rawurlencode($graphId).'/attachments/'.rawurlencode((string) ($attachment['id'] ?? '')).'/$value');

        if (! $response->successful() || $response->body() === '') {
            return null;
        }

        $name = trim((string) ($attachment['name'] ?? '')) ?: 'email';

        return ['name' => str_ends_with(mb_strtolower($name), '.eml') ? $name : $name.'.eml', 'contents' => $response->body(), 'mime' => 'message/rfc822'];
    }

    public static function checkedKey(string $senderKey): string
    {
        return 'mail_inbox_checked_at.'.$senderKey;
    }

    /** When a mailbox was last read for its emails by an automatic run — null: never. */
    public static function lastReadAt(string $senderKey): ?Carbon
    {
        $at = Setting::get(self::checkedKey($senderKey));

        return filled($at) ? Carbon::parse((string) $at) : null;
    }

    /**
     * When a mailbox was last read, to show beside it: the time, "Never read" for one set to be read that no run
     * has reached yet, "Not read" for one not set to be read (or not active).
     */
    public static function lastReadLabel(MailSender $mailbox): string
    {
        if (! $mailbox->is_active || ! $mailbox->toSender()['collects']) {
            return __('Not read');
        }

        return self::lastReadAt($mailbox->key)?->format(Dates::dateTimeFormat()) ?? __('Never read');
    }
}
