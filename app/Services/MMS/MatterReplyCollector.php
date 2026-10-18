<?php

namespace App\Services\MMS;

use App\Filament\Mms\Pages\UnfiledEmails;
use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\BulkMailRecipient;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\MatterParty;
use App\Models\MatterProgress;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Support\Tolerant;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Replies to a matter's emails, collected from the inbox of the mailbox
 * each went from (over IMAP for a cPanel mailbox, Microsoft Graph for a
 * Microsoft 365 one — which needs the Mail.Read application permission):
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
 */
class MatterReplyCollector
{
    /** How far back sent emails are watched for replies. */
    private const WATCH_DAYS = 90;

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

    /** @var array<int, array{matter: int, label: string, url: string, downloads: list<array{name: string, url: string}>}> matters whose emails were kept in the system only (they have no OneDrive folder), with the files to download and put there by hand */
    private array $unlinked = [];

    /** The original email (.eml) is kept when it is no bigger than this. */
    private const MAX_ORIGINAL_BYTES = 25 * 1024 * 1024;

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
     * @return array{replies: int, emails: int, unfiled: int, errors: list<string>, stopped: bool, unlinked: list<array{matter: int, label: string, url: string, downloads: list<array{name: string, url: string}>}>}
     */
    public function collect(?int $matterId = null, ?int $maxSeconds = null): array
    {
        $this->unlinked = [];
        $result = ['replies' => 0, 'emails' => 0, 'unfiled' => 0, 'errors' => [], 'stopped' => false, 'unlinked' => []];
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

        // Every mailbox anything has been sent from — still set up; set to keep every email, every
        // mailbox of the senders list (one never sent from still receives).
        $mailboxes = array_map('strval', array_keys(SenderMailer::all()));
        if (! self::keepsAll()) {
            $mailboxes = array_values(array_intersect(
                MatterEmail::query()->where('direction', MatterEmail::SENT)->distinct()->pluck('sender_key')->map(fn ($key) => (string) $key)->all(),
                $mailboxes,
            ));
        }

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
                'kept' => ['replies' => $result['replies'], 'emails' => $result['emails'], 'unfiled' => $result['unfiled']],
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
            return __('Started :when and has not finished (still running, or it was cut off).', ['when' => $started->format('d/m/Y H:i')]);
        }

        $kept = array_sum((array) ($last['kept'] ?? []));
        $line = Carbon::parse($last['finished_at'])->format('d/m/Y H:i').' — '.trans_choice('{0} nothing new|{1} 1 email kept|[2,*] :count emails kept', $kept, ['count' => $kept]);

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
            fn (array $file): array => ['name' => (string) $file['name'], 'url' => MatterEmail::fileUrl((string) $file['path'])],
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
     * @return array{replies: int, emails: int, unfiled: int}
     */
    private function handleAll(iterable $messages, Collection $sent, ?string $senderKey, ?int $matterId, ?float $deadline = null, ?array &$progress = null, ?CarbonInterface $checkpoint = null): array
    {
        $startedAt = now();
        $kept = ['replies' => 0, 'emails' => 0, 'unfiled' => 0];
        $own = array_map(fn (array $s): string => mb_strtolower((string) ($s['address'] ?? '')), SenderMailer::all());
        $progress = ['reached' => null, 'stopped' => false];
        $keepAll = self::keepsAll();
        $ignored = self::ignoredSenders();

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
                    continue;
                }
            }

            // Bodies read once, however often asked for.
            $message['body'] = self::once($message['body'] ?? '');
            $message['attachments'] = self::once($message['attachments'] ?? []);

            try {
                if ($answers = self::answers($message, $sent)) {
                    if ($matterId === null || (int) $answers->matter_id === $matterId) {
                        $this->keep($answers, $message, $id);
                        $this->markRead($message);
                        $kept['replies']++;
                    }

                    continue;
                }

                // Any other: from a party, about a matter of theirs — or, when set to keep every email,
                // from anyone. Never our own mailboxes', and only one that can be told apart.
                $from = mb_strtolower(trim((string) ($message['from'] ?? '')));
                if ($senderKey === null || $id === null || $from === '' || in_array($from, $own, true)) {
                    continue;
                }

                $match = $this->matcher->match($from, (string) ($message['subject'] ?? ''), $message['body']);

                if ($match['matter'] !== null && ($matterId === null || $match['matter'] === $matterId)) {
                    $this->keepIncoming(Matter::findOrFail($match['matter']), $message, $id, $senderKey, $match['by']);
                    $this->markRead($message);
                    $kept['emails']++;
                } elseif ($match['matter'] === null && $matterId === null && ($match['candidates'] !== [] || $keepAll)) {
                    // Its matter not certain — or none at all (a stranger's email, kept when set to keep
                    // every one): a person files it, or sets it aside, on the Unfiled emails page.
                    $this->hold($message, $id, $senderKey, $match['candidates']);
                    $this->markRead($message);
                    $kept['unfiled']++;
                }
            } catch (Throwable $e) {
                // Its row, if it was made, stays without files: once abandoned long enough, the
                // automatic run tries it again (retryAbandoned()).
                Log::warning('Email not kept: '.$e->getMessage(), ['message' => $id]);
            }
        }

        return $kept;
    }

    /** Setting: keep every email of the inbox, not only those from a matter's party (others go to Unfiled emails). */
    public const KEEP_ALL = 'mail_keep_all_inbox';

    public static function keepsAll(): bool
    {
        return (bool) Setting::get(self::KEEP_ALL, false);
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
            ->filter(fn (MatterEmail $email) => in_array($from, (array) $email->to, true)
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
    private function markRead(array $message): void
    {
        if (! ($message['mark_read'] ?? null) instanceof Closure) {
            return;
        }

        try {
            ($message['mark_read'])();
        } catch (Throwable $e) {
            Log::info('Email not marked as read in the mailbox: '.$e->getMessage(), ['message' => $message['message_id'] ?? null]);
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
        [$pdf, $files, $name, $source] = $this->rendered($message, (string) $answers->sender_key, true);
        $oneDrive = null;

        if ($matter) {
            $kept = $this->keepOnMatter($matter, $pdf, $files, $name, $answers->user_id, $source, $oneDrive, self::arrivedAt($message));
        } else {
            // No matter: its PDF and the files it came with, in a folder of its own.
            $kept = $this->keepLoose('email-replies/'.$reply->getKey(), $pdf, $files, $name);
        }

        // Where each went — for the campaign's recipients list to open. Written first, so the steps
        // after it are never repeated by a run that keeps the email again.
        $reply->update(['files' => $kept, 'onedrive' => $oneDrive]);

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
    private function keepIncoming(Matter $matter, array $message, string $id, string $senderKey, ?string $by): void
    {
        $email = $this->record($message, $id, $senderKey, ['matter_id' => $matter->getKey(), 'status' => MatterEmail::FILED, 'matched_by' => $by]);
        [$pdf, $files, $name, $source] = $this->rendered($message, $senderKey, false);

        $kept = $this->keepOnMatter($matter, $pdf, $files, $name, null, $source, $oneDrive, self::arrivedAt($message));
        $email->update(['files' => $kept, 'onedrive' => $oneDrive]);
        MatterProgressRecorder::emailReceived($email);
        MatterContact::learn($email);
        $this->tellMatter($email, $matter, count($files), $this->noteUnlinked($matter, $kept));
    }

    /**
     * A party's email whose matter is not certain: kept aside — its PDF and
     * files — until a person chooses (file()).
     *
     * @param  array<string, mixed>  $message
     * @param  list<int>  $candidates
     */
    private function hold(array $message, string $id, string $senderKey, array $candidates): void
    {
        $email = $this->record($message, $id, $senderKey, ['status' => MatterEmail::PENDING, 'candidates' => $candidates]);
        [$pdf, $files, $name, $source] = $this->rendered($message, $senderKey, false);

        $email->update(['files' => $this->keepLoose('email-unfiled/'.$email->getKey(), $pdf, $files, $name, $source)]);
        $this->tellUnfiled($email);
    }

    /**
     * A held email, filed by a person on the matter they chose: its files
     * moved there (and to its OneDrive), a step in its progress.
     */
    public function file(MatterEmail $email, Matter $matter, ?int $userId = null): void
    {
        $disk = Storage::disk('public');
        $stored = (array) ($email->files ?? []);
        $pdf = $stored[0] ?? null;
        $kept = [];
        $oneDrive = ['pdf' => false, 'eml' => false, 'attachments' => 0];

        foreach ($stored as $i => $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || ! $disk->exists($path)) {
                continue;
            }

            // The PDF in the received-emails folder; the files it came with
            // in a folder of the PDF's name beside it.
            $folder = MatterOneDriveFolders::receivedEmailsFolderFor($matter, $email->at);
            $oneDrive['folder'] = $folder;
            // The original email (.eml) goes to OneDrive only, beside the PDF; it is not kept in the system.
            if (($file['kind'] ?? null) === MatterEmail::SOURCE) {
                $oneDrive['eml'] = $this->archive->keepInOneDrive($matter, (string) $file['name'], (string) $disk->get($path), 'message/rfc822', $folder);
                $disk->delete($path);

                continue;
            }

            $contents = (string) $disk->get($path);
            $mime = (string) ($disk->mimeType($path) ?: 'application/octet-stream');

            if ($i === 0) {
                // The PDF: with the matter's attachments, and in OneDrive.
                $kept[] = ['name' => (string) $file['name'], 'path' => $this->archive->keepFile($matter, (string) $file['name'], $contents, $mime, $folder, $userId, inOneDrive: $saved)];
                $oneDrive['pdf'] = (bool) $saved;
            } else {
                // A file it came with: in OneDrive only, in a folder of the PDF's name.
                $attachment = $this->keepAttachment($matter, (string) $file['name'], $contents, $mime, $folder !== '' ? $folder.'/'.pathinfo((string) ($pdf['name'] ?? ''), PATHINFO_FILENAME) : '', $userId);
                $kept[] = ['name' => $attachment['name'], 'path' => $attachment['path']];
                $oneDrive['attachments'] += $attachment['saved'] ? 1 : 0;
            }

            $disk->delete($path);
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

        // As for an email put on its matter by itself: its people hear of it — but not who chose it.
        $this->tellMatter($email, $matter, max(count($kept) - 1, 0), $this->noteUnlinked($matter, $kept), $userId);
    }

    /** A held email that is no matter's: set aside, its files gone. */
    public function dismiss(MatterEmail $email): void
    {
        Storage::disk('public')->delete(array_filter(array_map(fn ($file) => $file['path'] ?? null, (array) ($email->files ?? []))));
        $email->update(['status' => MatterEmail::DISMISSED, 'files' => []]);
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
        if (! $oneDrive['pdf'] && $pdf !== '' && Storage::disk('public')->exists($pdf)) {
            $oneDrive['pdf'] = $this->archive->keepInOneDrive($to, (string) $email->files[0]['name'], (string) Storage::disk('public')->get($pdf), 'application/pdf', $oneDrive['folder']);
        }

        $email->update([
            'matter_id' => $to->getKey(),
            'matched_by' => MatterEmail::BY_HAND,
            'onedrive' => ['pdf' => $oneDrive['pdf'], 'eml' => $oneDrive['eml'], 'attachments' => $oneDrive['attachments'], 'folder' => $oneDrive['folder']],
        ]);

        // Its step in the progress, on the other matter.
        MatterProgress::query()->where('source_type', $email->getMorphClass())->where('source_id', $email->getKey())->update(['matter_id' => $to->getKey()]);
        if ($email->parent_id === null) {
            MatterProgressRecorder::emailReceived($email, $userId);
        }

        MatterContact::learn($email);

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

        return MatterEmail::create([
            'direction' => MatterEmail::RECEIVED,
            'sender_key' => $senderKey,
            'message_id' => $id,
            'subject' => mb_substr((string) ($message['subject'] ?? ''), 0, 500),
            'from' => mb_substr(trim($fromName.($fromName !== $from ? ' <'.$from.'>' : '')), 0, 255),
            'to' => array_values(array_filter((array) ($message['to'] ?? []))),
            'cc' => array_values(array_filter((array) ($message['cc'] ?? []))),
            'at' => self::arrivedAt($message),
            // Unread in the mailbox before it was collected (null: not known).
            'was_unread' => isset($message['was_read']) ? ! $message['was_read'] : null,
            ...$attributes,
        ]);
    }

    /**
     * A received email as a PDF, the files it came with, and its name —
     * for the party who sent it.
     *
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: list<array{name: string, contents: string, mime?: string}>, 2: string, 3: ?string} the PDF, the files, the name, and the original email (.eml) when the mailbox gives it
     */
    private function rendered(array $message, string $senderKey, bool $reply): array
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
            (string) (SenderMailer::all()[$senderKey]['name'] ?? $senderKey),
        );

        return [$pdf, $files, SentEmailArchive::emailName(false, self::partyName($from) ?? $fromName, (string) ($message['subject'] ?? ''), $at, $reply), self::original($message)];
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
    private function keepOnMatter(Matter $matter, string $pdf, array $files, string $name, ?int $userId, ?string $source = null, &$oneDrive = null, ?CarbonInterface $at = null): array
    {
        $folder = MatterOneDriveFolders::receivedEmailsFolderFor($matter, $at);
        // The folder it goes in is kept with it, to say where it is.
        $oneDrive = ['pdf' => false, 'eml' => false, 'attachments' => 0, 'folder' => $folder];
        $kept = [['name' => $name.'.pdf', 'path' => $this->archive->keepFile($matter, $name.'.pdf', $pdf, 'application/pdf', $folder, $userId, inOneDrive: $oneDrive['pdf'])]];

        foreach ($files as $file) {
            $attachment = $this->keepAttachment($matter, (string) $file['name'], (string) $file['contents'], (string) ($file['mime'] ?? 'application/octet-stream'), $folder !== '' ? $folder.'/'.$name : '', $userId);
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
     * Whether an inline part is only a picture the email's own text shows ("cid:…") — a
     * signature's logo — and not a file that came with it. Anything else the mail program
     * marked "inline" (a PDF from Apple Mail, Outlook for Mac or an iPhone) is a file.
     */
    public static function isShownInText(string $mime, string $contentId, string $html): bool
    {
        $contentId = trim($contentId, " <>\t\r\n");

        // A picture the text shows by its Content-ID, or one with no Content-ID at all (it can only be decoration).
        return str_starts_with(strtolower($mime), 'image/')
            && ($contentId === '' || stripos($html, 'cid:'.$contentId) !== false);
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

    /** Whoever sent the email: told of the reply. */
    private function tell(MatterEmail $reply, MatterEmail $answers, int $files): void
    {
        $user = $answers->user;
        if (! $user) {
            return;
        }

        try {
            Notification::make()
                ->success()
                ->icon('heroicon-o-envelope-open')
                ->title($reply->matter
                    ? __(':name replied on matter :matter', ['name' => $reply->from, 'matter' => $reply->matter->year.'/'.$reply->matter->number])
                    : __(':name replied to a bulk email', ['name' => $reply->from]))
                ->body(trim($reply->subject.($files ? ' — '.trans_choice(':count file|:count files', $files, ['count' => $files]) : '')))
                ->actions([
                    Action::make('view')->label(__('View'))->url(self::replyUrl($reply, $answers))->markAsRead(),
                ])
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            Log::info('Reply notification not sent: '.$e->getMessage());
        }
    }

    /**
     * The people of these matters — the users of their experts and
     * assistants.
     *
     * @param  list<int>  $matterIds
     * @return Collection<int, User>
     */
    private static function matterUsers(array $matterIds): Collection
    {
        $partyIds = MatterParty::query()->whereIn('matter_id', $matterIds)->where('role', 'expert')->pluck('party_id');

        $people = User::query()->whereIn('id', Party::query()->whereIn('id', $partyIds)->whereNotNull('user_id')->select('user_id'))->get();

        // The super admins hear of every email that arrives too.
        return $people->merge(self::superAdmins())->unique('id')->values();
    }

    /**
     * @return Collection<int, User>
     */
    private static function superAdmins(): Collection
    {
        try {
            return User::role(Utils::getSuperAdminName())->get();
        } catch (Throwable) {
            // No such role set up (yet).
            return new Collection;
        }
    }

    /** The matter's people: told of a party's email kept on it. */
    /**
     * @param  list<array{name: string, url: string}>  $downloads  when the matter has no OneDrive folder: the email's files, to download and put there by hand
     * @param  int|null  $exceptUserId  not told: whoever filed it by hand
     */
    private function tellMatter(MatterEmail $email, Matter $matter, int $files, array $downloads = [], ?int $exceptUserId = null): void
    {
        $notInOneDrive = $downloads !== [];
        $downloads = array_values(array_filter($downloads, fn (array $file) => filled($file['url'])));
        $users = self::matterUsers([(int) $matter->getKey()])->reject(fn (User $user): bool => $exceptUserId !== null && $user->getKey() === $exceptUserId);
        if ($users->isEmpty()) {
            return;
        }

        try {
            Notification::make()
                ->success()
                ->icon('heroicon-o-inbox-arrow-down')
                ->title(__(':name emailed on matter :matter', ['name' => $email->from, 'matter' => $matter->year.'/'.$matter->number]))
                // What was kept, one point each.
                ->body(self::bullets([
                    trim($email->subject),
                    $files ? trans_choice(':count file|:count files', $files, ['count' => $files]) : null,
                    $notInOneDrive ? __('Not saved in OneDrive: this matter is not linked to a OneDrive folder, so the email and its files are kept in the system only.') : null,
                ]))
                ->actions([
                    Action::make('view')->label(__('View'))->url(MatterResource::getUrl('view', ['record' => $matter], panel: 'mms'))->markAsRead(),
                    // No folder to find it in: the email, to download and keep by hand.
                    ...($downloads !== [] ? [Action::make('download')->label(__('Download the email'))->icon('heroicon-o-arrow-down-tray')->url($downloads[0]['url'], shouldOpenInNewTab: true)] : []),
                ])
                ->sendToDatabase($users);
        } catch (Throwable $e) {
            Log::info('Email notification not sent: '.$e->getMessage());
        }
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
        $users = self::matterUsers($candidates);
        if ($users->isEmpty()) {
            return;
        }

        $matters = Matter::query()->whereKey($candidates)->get(['id', 'year', 'number'])->map(fn (Matter $m) => $m->year.'/'.$m->number)->all();

        try {
            Notification::make()
                ->warning()
                ->icon('heroicon-o-inbox-stack')
                ->title(__('Which matter is this email from :name about?', ['name' => $email->from]))
                // The subject, then the matters it may be about, one point each (none: a sender on no matter).
                ->body(self::bullets($matters === [] ? [trim($email->subject)] : [trim($email->subject), new HtmlString(e(__('It may be about:')).self::bullets($matters)->toHtml())]))
                ->actions([
                    Action::make('choose')->label(__('Choose its matter'))->url(UnfiledEmails::getUrl(panel: 'mms'))->markAsRead(),
                ])
                ->sendToDatabase($users);
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
                        // Not a picture the email's own text shows (a signature's logo) — but a PDF
                        // that Apple Mail or Outlook sent "inline" is a file it came with.
                        if ($attachment->disposition === 'inline' && self::isShownInText((string) $attachment->content_type, (string) $attachment->id, $html)) {
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
     * @return list<string>
     */
    private static function imapAddresses(Message $message, string $header): array
    {
        return array_values(array_map(fn (Address $a): string => $a->mail, array_filter($message->get($header)?->all() ?? [], fn ($a) => $a instanceof Address)));
    }

    /**
     * New messages in a Microsoft 365 mailbox's inbox.
     *
     * @return iterable<array<string, mixed>>
     */
    private function fromGraph(string $mailbox, CarbonInterface $since): iterable
    {
        $base = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox);
        $url = $base.'/mailFolders/Inbox/messages';
        $query = [
            '$filter' => 'receivedDateTime ge '.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$select' => 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,internetMessageId,internetMessageHeaders,body,hasAttachments,isRead',
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
                $headers = collect($item['internetMessageHeaders'] ?? [])->mapWithKeys(fn ($h) => [mb_strtolower((string) ($h['name'] ?? '')) => (string) ($h['value'] ?? '')]);
                $graphId = (string) $item['id'];

                yield [
                    'message_id' => (string) ($item['internetMessageId'] ?? ''),
                    'references' => preg_split('/\s+/', trim($headers->get('in-reply-to', '').' '.$headers->get('references', ''))) ?: [],
                    'subject' => (string) ($item['subject'] ?? ''),
                    'from' => (string) ($item['from']['emailAddress']['address'] ?? ''),
                    'from_name' => (string) ($item['from']['emailAddress']['name'] ?? ''),
                    'to' => array_values(array_filter(array_map(fn ($r) => $r['emailAddress']['address'] ?? null, $item['toRecipients'] ?? []))),
                    'cc' => array_values(array_filter(array_map(fn ($r) => $r['emailAddress']['address'] ?? null, $item['ccRecipients'] ?? []))),
                    'at' => (string) ($item['receivedDateTime'] ?? now()->toIso8601String()),
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
                            ->filter(fn ($a) => isset($a['contentBytes'])
                                && ! (($a['isInline'] ?? false) && self::isShownInText((string) ($a['contentType'] ?? ''), (string) ($a['contentId'] ?? ''), (string) ($item['body']['content'] ?? ''))))
                            ->map(fn ($a): array => ['name' => (string) ($a['name'] ?? 'attachment'), 'contents' => (string) base64_decode($a['contentBytes']), 'mime' => (string) ($a['contentType'] ?? 'application/octet-stream')])
                            ->values()
                            ->all();
                    },
                ];
            }

            // The next link carries the query itself.
            $url = $body['@odata.nextLink'] ?? null;
            $query = null;
        }
    }

    public static function checkedKey(string $senderKey): string
    {
        return 'mail_inbox_checked_at.'.$senderKey;
    }
}
