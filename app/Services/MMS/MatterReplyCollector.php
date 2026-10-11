<?php

namespace App\Services\MMS;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\MatterEmail;
use App\Models\Party;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 */
class MatterReplyCollector
{
    /** How far back sent emails are watched for replies. */
    private const WATCH_DAYS = 90;

    public function __construct(
        private readonly SentFolder $mailboxes,
        private readonly OutlookCalendarService $graph,
        private readonly SentEmailArchive $archive,
    ) {}

    /**
     * Every mailbox a watched email went from, read for new replies — or
     * only those of one matter, read from its first email on.
     *
     * @return array{replies: int, errors: list<string>}
     */
    public function collect(?int $matterId = null): array
    {
        $result = ['replies' => 0, 'errors' => []];

        $sent = MatterEmail::query()
            ->where('direction', MatterEmail::SENT)
            ->where('at', '>=', now()->subDays(self::WATCH_DAYS))
            ->when($matterId, fn ($q) => $q->where('matter_id', $matterId))
            ->get()
            ->groupBy('sender_key');

        foreach ($sent as $senderKey => $emails) {
            try {
                $sender = SenderMailer::sender((string) $senderKey);
                $checked = $matterId ? null : Setting::get(self::checkedKey((string) $senderKey));
                $since = $checked ? Carbon::parse($checked)->subHour() : $emails->min('at')->copy()->subHour();
                $startedAt = now();

                $messages = SenderMailer::isMicrosoft($sender)
                    ? $this->fromGraph((string) $sender['address'], $since)
                    : $this->fromImap((string) $senderKey, $sender, $since);

                $result['replies'] += $this->handle($messages, $emails);

                if (! $matterId) {
                    Setting::set(self::checkedKey((string) $senderKey), $startedAt->toDateTimeString(), 'mail');
                }
            } catch (Throwable $e) {
                Log::warning('Replies not collected from '.$senderKey.': '.$e->getMessage());
                $result['errors'][] = $senderKey.': '.$e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Messages from an inbox, matched to the emails they answer and kept.
     * Each message: message_id, references (ids), subject, from, from_name,
     * to, cc, at, and — fetched only for a reply — body and attachments.
     *
     * @param  iterable<array<string, mixed>>  $messages
     * @param  Collection<int, MatterEmail>  $sent
     */
    public function handle(iterable $messages, Collection $sent): int
    {
        $kept = 0;

        foreach ($messages as $message) {
            $id = MatterEmail::messageId($message['message_id'] ?? null);

            if ($id !== null && MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->where('message_id', $id)->exists()) {
                continue;
            }

            $answers = self::answers($message, $sent);
            if (! $answers) {
                continue;
            }

            try {
                $this->keep($answers, $message, $id);
                $kept++;
            } catch (Throwable $e) {
                Log::warning('Reply not kept: '.$e->getMessage(), ['matter' => $answers->matter_id]);
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
     * @param  array<string, mixed>  $message
     */
    private function keep(MatterEmail $answers, array $message, ?string $id): void
    {
        $matter = $answers->matter;
        if (! $matter) {
            return;
        }

        $body = $message['body'] instanceof Closure ? ($message['body'])() : (string) ($message['body'] ?? '');
        $files = $message['attachments'] instanceof Closure ? ($message['attachments'])() : (array) ($message['attachments'] ?? []);
        $at = isset($message['at']) ? Carbon::parse($message['at']) : now();
        $from = trim((string) ($message['from'] ?? ''));
        $fromName = trim((string) ($message['from_name'] ?? '')) ?: $from;

        $reply = MatterEmail::create([
            'matter_id' => $matter->getKey(),
            'direction' => MatterEmail::RECEIVED,
            'parent_id' => $answers->getKey(),
            'sender_key' => $answers->sender_key,
            'message_id' => $id,
            'subject' => mb_substr((string) ($message['subject'] ?? ''), 0, 500),
            'from' => mb_substr(trim($fromName.($fromName !== $from ? ' <'.$from.'>' : '')), 0, 255),
            'to' => array_values(array_filter((array) ($message['to'] ?? []))),
            'at' => $at,
        ]);

        $pdf = EmailPdf::render(
            (string) ($message['subject'] ?? ''),
            $body,
            ['name' => $fromName, 'address' => $from],
            '',
            array_values((array) ($message['to'] ?? [])),
            array_values((array) ($message['cc'] ?? [])),
            [],
            array_column($files, 'name'),
            $at,
        );

        // Named for the party who replied: the reply's PDF in the replies
        // folder, the files it came with in a folder of the same name
        // beside it.
        $folder = MatterOneDriveFolders::receivedEmailsFolder();
        $name = SentEmailArchive::emailName(false, self::partyName($from) ?? $fromName, (string) ($message['subject'] ?? ''), $at);
        $this->archive->keepFile($matter, $name.'.pdf', $pdf, 'application/pdf', $folder, $answers->user_id);

        foreach ($files as $file) {
            $this->archive->keepFile($matter, (string) $file['name'], (string) $file['contents'], (string) ($file['mime'] ?? 'application/octet-stream'), $folder !== '' ? $folder.'/'.$name : '', $answers->user_id);
        }

        MatterProgressRecorder::replyReceived($reply->setRelation('parent', $answers));
        $this->tell($reply, $answers, count($files));
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
                ->title(__(':name replied on matter :matter', ['name' => $reply->from, 'matter' => $reply->matter?->year.'/'.$reply->matter?->number]))
                ->body(trim($reply->subject.($files ? ' — '.trans_choice(':count file|:count files', $files, ['count' => $files]) : '')))
                ->actions([
                    Action::make('view')->label(__('View'))->url(MatterResource::getUrl('view', ['record' => $reply->matter_id], panel: 'mms'))->markAsRead(),
                ])
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            Log::info('Reply notification not sent: '.$e->getMessage());
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
        return 'mail_replies_checked_at.'.$senderKey;
    }
}
