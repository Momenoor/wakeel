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
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Address;
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

    /** How far back a mailbox is first read for the parties' other emails. */
    private const FIRST_DAYS = 30;

    public function __construct(
        private readonly SentFolder $mailboxes,
        private readonly OutlookCalendarService $graph,
        private readonly SentEmailArchive $archive,
        private readonly IncomingEmailMatcher $matcher,
    ) {}

    /**
     * Every mailbox a letter or email has gone from, read for what came in
     * since it was last read: replies to the emails sent, and any other
     * email from a matter's party. Or — for one matter — read back from its
     * first email (FIRST_DAYS at least), for its emails only.
     *
     * @return array{replies: int, emails: int, unfiled: int, errors: list<string>}
     */
    public function collect(?int $matterId = null): array
    {
        $result = ['replies' => 0, 'emails' => 0, 'unfiled' => 0, 'errors' => []];

        $sent = MatterEmail::query()
            ->where('direction', MatterEmail::SENT)
            ->where('at', '>=', now()->subDays(self::WATCH_DAYS))
            ->when($matterId, fn ($q) => $q->where('matter_id', $matterId))
            ->get()
            ->groupBy('sender_key');

        // Every mailbox anything has been sent from — still set up.
        $mailboxes = array_values(array_intersect(
            MatterEmail::query()->where('direction', MatterEmail::SENT)->distinct()->pluck('sender_key')->map(fn ($key) => (string) $key)->all(),
            array_map('strval', array_keys(SenderMailer::all())),
        ));

        foreach ($mailboxes as $senderKey) {
            $emails = $sent->get($senderKey, collect());

            try {
                $sender = SenderMailer::sender($senderKey);
                $checked = $matterId ? null : Setting::get(self::checkedKey($senderKey));
                $first = now()->subDays(self::FIRST_DAYS);
                $since = $checked ? Carbon::parse($checked)->subHour() : ($emails->isNotEmpty() ? $emails->min('at')->copy()->subHour()->min($first) : $first);
                $startedAt = now();

                $messages = SenderMailer::isMicrosoft($sender)
                    ? $this->fromGraph((string) $sender['address'], $since)
                    : $this->fromImap($senderKey, $sender, $since);

                foreach ($this->handleAll($messages, $emails, $senderKey, $matterId) as $kind => $count) {
                    $result[$kind] += $count;
                }

                if (! $matterId) {
                    Setting::set(self::checkedKey($senderKey), $startedAt->toDateTimeString(), 'mail');
                }
            } catch (Throwable $e) {
                Log::warning('Emails not collected from '.$senderKey.': '.$e->getMessage());
                $result['errors'][] = $senderKey.': '.$e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Messages from an inbox, kept: replies to the emails sent, and —
     * given the mailbox they came to — the parties' other emails. How many.
     *
     * @param  iterable<array<string, mixed>>  $messages
     * @param  Collection<int, MatterEmail>  $sent
     */
    public function handle(iterable $messages, Collection $sent, ?string $senderKey = null, ?int $matterId = null): int
    {
        return array_sum($this->handleAll($messages, $sent, $senderKey, $matterId));
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
    private function handleAll(iterable $messages, Collection $sent, ?string $senderKey, ?int $matterId): array
    {
        $kept = ['replies' => 0, 'emails' => 0, 'unfiled' => 0];
        $own = array_map(fn (array $s): string => mb_strtolower((string) ($s['address'] ?? '')), SenderMailer::all());

        foreach ($messages as $message) {
            $id = MatterEmail::messageId($message['message_id'] ?? null);

            if ($id !== null && MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->where('message_id', $id)->exists()) {
                continue;
            }

            // Bodies read once, however often asked for.
            $message['body'] = self::once($message['body'] ?? '');
            $message['attachments'] = self::once($message['attachments'] ?? []);

            try {
                if ($answers = self::answers($message, $sent)) {
                    if ($matterId === null || (int) $answers->matter_id === $matterId) {
                        $this->keep($answers, $message, $id);
                        $kept['replies']++;
                    }

                    continue;
                }

                // Any other: from a party, about a matter of theirs. Never
                // our own mailboxes', and only one that can be told apart.
                $from = mb_strtolower(trim((string) ($message['from'] ?? '')));
                if ($senderKey === null || $id === null || $from === '' || in_array($from, $own, true)) {
                    continue;
                }

                $match = $this->matcher->match($from, (string) ($message['subject'] ?? ''), $message['body']);

                if ($match['matter'] !== null && ($matterId === null || $match['matter'] === $matterId)) {
                    $this->keepIncoming(Matter::findOrFail($match['matter']), $message, $id, $senderKey, $match['by']);
                    $kept['emails']++;
                } elseif ($match['matter'] === null && $match['candidates'] !== [] && $matterId === null) {
                    $this->hold($message, $id, $senderKey, $match['candidates']);
                    $kept['unfiled']++;
                }
            } catch (Throwable $e) {
                Log::warning('Email not kept: '.$e->getMessage(), ['message' => $id]);
            }
        }

        return $kept;
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
        ]);
        [$pdf, $files, $name] = $this->rendered($message, (string) $answers->sender_key, true);

        if ($matter) {
            $kept = $this->keepOnMatter($matter, $pdf, $files, $name, $answers->user_id);
            MatterProgressRecorder::replyReceived($reply->setRelation('parent', $answers));
            // Whoever they put in To or CC: theirs, on this matter.
            MatterContact::learn($reply);
        } else {
            // No matter: its PDF and the files it came with, in a folder of its own.
            $kept = $this->keepLoose('email-replies/'.$reply->getKey(), $pdf, $files, $name);
        }

        // Where each went — for the campaign's recipients list to open.
        $reply->update(['files' => $kept]);
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
        [$pdf, $files, $name] = $this->rendered($message, $senderKey, false);

        $email->update(['files' => $this->keepOnMatter($matter, $pdf, $files, $name, null)]);
        MatterProgressRecorder::emailReceived($email);
        MatterContact::learn($email);
        $this->tellMatter($email, $matter, count($files));
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
        [$pdf, $files, $name] = $this->rendered($message, $senderKey, false);

        $email->update(['files' => $this->keepLoose('email-unfiled/'.$email->getKey(), $pdf, $files, $name)]);
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

        foreach ($stored as $i => $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || ! $disk->exists($path)) {
                continue;
            }

            // The PDF in the received-emails folder; the files it came with
            // in a folder of the PDF's name beside it.
            $folder = MatterOneDriveFolders::receivedEmailsFolder();
            $subfolder = $i === 0 || $folder === '' ? $folder : $folder.'/'.pathinfo((string) ($pdf['name'] ?? ''), PATHINFO_FILENAME);
            $kept[] = ['name' => (string) $file['name'], 'path' => $this->archive->keepFile($matter, (string) $file['name'], (string) $disk->get($path), (string) ($disk->mimeType($path) ?: 'application/octet-stream'), $subfolder, $userId)];
            $disk->delete($path);
        }

        $email->update([
            'matter_id' => $matter->getKey(),
            'status' => MatterEmail::FILED,
            'matched_by' => MatterEmail::BY_HAND,
            'files' => array_values(array_filter($kept, fn (array $file) => filled($file['path']))),
        ]);

        MatterProgressRecorder::emailReceived($email, $userId);
        MatterContact::learn($email);
    }

    /** A held email that is no matter's: set aside, its files gone. */
    public function dismiss(MatterEmail $email): void
    {
        Storage::disk('public')->delete(array_filter(array_map(fn ($file) => $file['path'] ?? null, (array) ($email->files ?? []))));
        $email->update(['status' => MatterEmail::DISMISSED, 'files' => []]);
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
            'at' => isset($message['at']) ? Carbon::parse($message['at']) : now(),
            ...$attributes,
        ]);
    }

    /**
     * A received email as a PDF, the files it came with, and its name —
     * for the party who sent it.
     *
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: list<array{name: string, contents: string, mime?: string}>, 2: string}
     */
    private function rendered(array $message, string $senderKey, bool $reply): array
    {
        $body = $message['body'] instanceof Closure ? ($message['body'])() : (string) ($message['body'] ?? '');
        $files = $message['attachments'] instanceof Closure ? ($message['attachments'])() : (array) ($message['attachments'] ?? []);
        $at = isset($message['at']) ? Carbon::parse($message['at']) : now();
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

        return [$pdf, $files, SentEmailArchive::emailName(false, self::partyName($from) ?? $fromName, (string) ($message['subject'] ?? ''), $at, $reply)];
    }

    /**
     * On the matter: the PDF in its received-emails folder, the files it
     * came with in a folder of the same name beside it. Where each went.
     *
     * @param  list<array{name: string, contents: string, mime?: string}>  $files
     * @return list<array{name: string, path: string}>
     */
    private function keepOnMatter(Matter $matter, string $pdf, array $files, string $name, ?int $userId): array
    {
        $folder = MatterOneDriveFolders::receivedEmailsFolder();
        $kept = [['name' => $name.'.pdf', 'path' => $this->archive->keepFile($matter, $name.'.pdf', $pdf, 'application/pdf', $folder, $userId)]];

        foreach ($files as $file) {
            $kept[] = ['name' => (string) $file['name'], 'path' => $this->archive->keepFile($matter, (string) $file['name'], (string) $file['contents'], (string) ($file['mime'] ?? 'application/octet-stream'), $folder !== '' ? $folder.'/'.$name : '', $userId)];
        }

        return array_values(array_filter($kept, fn (array $file) => filled($file['path'])));
    }

    /**
     * Of no matter (yet): the PDF and its files in a folder of their own.
     *
     * @param  list<array{name: string, contents: string, mime?: string}>  $files
     * @return list<array{name: string, path: string}>
     */
    private function keepLoose(string $folder, string $pdf, array $files, string $name): array
    {
        $kept = [['name' => $name.'.pdf', 'path' => $this->archive->keepLoose($folder, $name.'.pdf', $pdf)]];

        foreach ($files as $file) {
            $kept[] = ['name' => (string) $file['name'], 'path' => $this->archive->keepLoose($folder.'/'.$name, (string) $file['name'], (string) $file['contents'])];
        }

        return $kept;
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

        return User::query()->whereIn('id', Party::query()->whereIn('id', $partyIds)->whereNotNull('user_id')->select('user_id'))->get();
    }

    /** The matter's people: told of a party's email kept on it. */
    private function tellMatter(MatterEmail $email, Matter $matter, int $files): void
    {
        $users = self::matterUsers([(int) $matter->getKey()]);
        if ($users->isEmpty()) {
            return;
        }

        try {
            Notification::make()
                ->success()
                ->icon('heroicon-o-inbox-arrow-down')
                ->title(__(':name emailed on matter :matter', ['name' => $email->from, 'matter' => $matter->year.'/'.$matter->number]))
                ->body(trim($email->subject.($files ? ' — '.trans_choice(':count file|:count files', $files, ['count' => $files]) : '')))
                ->actions([
                    Action::make('view')->label(__('View'))->url(MatterResource::getUrl('view', ['record' => $matter], panel: 'mms'))->markAsRead(),
                ])
                ->sendToDatabase($users);
        } catch (Throwable $e) {
            Log::info('Email notification not sent: '.$e->getMessage());
        }
    }

    /** The people of each matter it may be about: asked to choose. */
    private function tellUnfiled(MatterEmail $email): void
    {
        $candidates = array_map('intval', (array) $email->candidates);
        $users = self::matterUsers($candidates);
        if ($users->isEmpty()) {
            return;
        }

        $matters = Matter::query()->whereKey($candidates)->get(['id', 'year', 'number'])->map(fn (Matter $m) => $m->year.'/'.$m->number)->implode('، ');

        try {
            Notification::make()
                ->warning()
                ->icon('heroicon-o-inbox-stack')
                ->title(__('Which matter is this email from :name about?', ['name' => $email->from]))
                ->body(trim($email->subject.' — '.__('It may be about :matters.', ['matters' => $matters])))
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
        $headers = $inbox->query()->whereSince($since->copy()->startOfDay())->leaveUnread()->setFetchBody(false)->setFetchFlags(false)->get();

        foreach ($headers as $header) {
            $from = collect($header->get('from')?->all() ?? [])->first(fn ($a) => $a instanceof Address);
            $uid = (int) $header->uid;

            yield [
                'message_id' => (string) $header->getMessageId(),
                'references' => [...(array) ($header->get('in_reply_to')?->all() ?? []), ...(array) ($header->get('references')?->all() ?? [])],
                'subject' => SentMailImporter::decodeHeader((string) $header->getSubject()),
                'from' => (string) ($from?->mail ?? ''),
                'from_name' => SentMailImporter::decodeHeader((string) ($from?->personal ?? '')),
                'to' => self::imapAddresses($header, 'to'),
                'cc' => self::imapAddresses($header, 'cc'),
                'at' => $header->getDate()->toDate()->toDateTimeString(),
                'body' => function () use ($inbox, $uid): string {
                    $message = $inbox->query()->leaveUnread()->setFetchFlags(false)->getMessageByUid($uid);

                    return $message->hasHTMLBody() ? $message->getHTMLBody() : nl2br(e($message->getTextBody()));
                },
                'attachments' => function () use ($inbox, $uid): array {
                    $message = $inbox->query()->leaveUnread()->setFetchFlags(false)->getMessageByUid($uid);
                    $files = [];

                    foreach ($message->getAttachments() as $attachment) {
                        // Not a signature's inline picture.
                        if ($attachment->disposition === 'inline' && filled($attachment->id)) {
                            continue;
                        }

                        $files[] = [
                            'name' => SentMailImporter::decodeHeader((string) ($attachment->name ?: $attachment->filename)) ?: 'attachment',
                            'contents' => (string) $attachment->content,
                            'mime' => (string) ($attachment->content_type ?: 'application/octet-stream'),
                        ];
                    }

                    return $files;
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
            '$select' => 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,internetMessageId,internetMessageHeaders,body,hasAttachments',
            '$top' => 50,
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
                    'body' => ($item['body']['contentType'] ?? 'html') === 'html' ? (string) ($item['body']['content'] ?? '') : nl2br(e((string) ($item['body']['content'] ?? ''))),
                    'attachments' => function () use ($base, $graphId, $item): array {
                        if (! ($item['hasAttachments'] ?? false)) {
                            return [];
                        }

                        $response = Http::withToken($this->graph->getAccessToken())->timeout(120)->get($base.'/messages/'.rawurlencode($graphId).'/attachments');

                        return collect($response->successful() ? ($response->json()['value'] ?? []) : [])
                            ->filter(fn ($a) => ! ($a['isInline'] ?? false) && isset($a['contentBytes']))
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

    private static function checkedKey(string $senderKey): string
    {
        // Read for every email, not replies only: read afresh from FIRST_DAYS back.
        return 'mail_inbox_checked_at.'.$senderKey;
    }
}
