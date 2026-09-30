<?php

namespace App\Services\MMS;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Helpers\HtmlFormatter;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailLog;
use App\Models\BulkMailRecipient;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Message;

/**
 * Brings emails already sent by hand — outside the system — into a bulk
 * mail campaign, recorded as sent: one recipient per email, on the date it
 * was sent, keeping that email's own subject and body (they differ — the
 * company name …) for its PDF and view. The campaign is created completed,
 * so nothing is ever sent again. An email already brought in (same
 * Message-ID) is skipped, so running it twice adds nothing.
 *
 * Reads the sender mailbox's sent mail: over IMAP for a cPanel (SMTP)
 * sender — every folder that may hold it (Sent, Sent Items …) — or through
 * Microsoft Graph for a Microsoft 365 sender (which needs the Mail.Read
 * application permission). run() reports as it goes (SentMailImportProgress).
 */
class SentMailImporter
{
    /** Reports progress: (changes, a line saying what it did). */
    private ?Closure $report = null;

    public function __construct(
        private readonly SentFolder $sentFolder,
        private readonly OutlookCalendarService $graph,
    ) {}

    /**
     * The whole import, reporting into the given progress run. Never throws:
     * a failure is recorded on the run for the progress window to show.
     */
    public function run(string $runId, string $name, string $senderKey, string $subjectContains, CarbonInterface $from, CarbonInterface $to, int $userId): void
    {
        $this->report = fn (array $changes = [], ?string $step = null) => SentMailImportProgress::update($runId, $changes, $step);

        try {
            $messages = $this->sentMessages($senderKey, $subjectContains, $from, $to);
            $campaign = $this->import($name, $senderKey, $messages, $userId);

            SentMailImportProgress::update($runId, [
                'status' => SentMailImportProgress::DONE,
                'campaign_id' => $campaign->id,
            ], __('Done: :count emails imported.', ['count' => $campaign->total_recipients]));
        } catch (Throwable $e) {
            SentMailImportProgress::update($runId, [
                'status' => SentMailImportProgress::FAILED,
                'error' => $e->getMessage(),
            ], $e->getMessage());
        } finally {
            $this->report = null;
        }
    }

    /**
     * Emails sent from the sender's mailbox in a date range whose subject
     * contains the given text, oldest first, each once.
     *
     * @return list<array<string, mixed>>
     */
    public function sentMessages(string $senderKey, string $subjectContains, CarbonInterface $from, CarbonInterface $to): array
    {
        $sender = SenderMailer::sender($senderKey);

        $messages = SenderMailer::isMicrosoft($sender)
            ? $this->fromGraph((string) $sender['address'], $subjectContains, $from, $to)
            : $this->fromImap($senderKey, $subjectContains, $from, $to);

        usort($messages, fn (array $a, array $b): int => strcmp((string) $a['sentDateTime'], (string) $b['sentDateTime']));

        return $messages;
    }

    /**
     * Create the completed campaign and its sent recipients.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function import(string $name, string $senderKey, array $messages, int $userId): BulkMailCampaign
    {
        $known = BulkMailRecipient::query()
            ->whereIn('message_id', array_filter(array_column($messages, 'internetMessageId')))
            ->pluck('message_id')
            ->all();

        $messages = array_values(array_filter(
            $messages,
            fn (array $message): bool => ! in_array($message['internetMessageId'] ?? null, $known, true) && self::addresses($message['toRecipients'] ?? []) !== [],
        ));

        if ($known !== []) {
            $this->report([], __(':count were brought in before and are skipped.', ['count' => count($known)]));
        }

        if ($messages === []) {
            throw new RuntimeException(__('No new sent emails to bring in — none matched, or they were brought in already.'));
        }

        $this->report([], __('Saving :count emails into the campaign…', ['count' => count($messages)]));

        $attachments = [];

        foreach ($messages as $message) {
            if (! empty($message['attachments'])) {
                $attachments = array_values($message['attachments']);
                break;
            }
        }

        return DB::transaction(function () use ($name, $senderKey, $messages, $userId, $attachments): BulkMailCampaign {
            $first = $messages[0];

            $campaign = BulkMailCampaign::create([
                'name' => $name,
                'subject' => (string) ($first['subject'] ?? $name),
                'body' => HtmlFormatter::cleanHtml((string) ($first['body']['content'] ?? '')),
                'from_sender_key' => $senderKey,
                'status' => BulkMailCampaignStatus::Completed,
                'total_recipients' => count($messages),
                // The attachment every one of these emails carried.
                'has_attachment' => $attachments !== [],
                'attachment_path' => $attachments ?: null,
                'sent_count' => count($messages),
                'failed_count' => 0,
                'created_by' => $userId,
            ]);

            foreach ($messages as $i => $message) {
                $to = $message['toRecipients'] ?? [];
                $sentAt = Carbon::parse($message['sentDateTime'])->setTimezone(config('app.timezone'));

                $recipient = BulkMailRecipient::create([
                    'campaign_id' => $campaign->id,
                    'email' => self::addresses($to),
                    // Who the letter is addressed to ("السادة/ Arco Interiors LLC"),
                    // else the To address's display name.
                    'name' => self::addressee((string) ($message['body']['content'] ?? '')) ?? (($to[0]['emailAddress']['name'] ?? null) ?: null),
                    'cc_emails' => self::addresses($message['ccRecipients'] ?? []) ?: null,
                    'status' => BulkMailRecipientStatus::Sent,
                    'sent_at' => $sentAt,
                    'message_id' => $message['internetMessageId'] ?? null,
                    'attempt_count' => 1,
                    // Each email as it went — they differ (the company name).
                    'sent_subject' => (string) ($message['subject'] ?? ''),
                    'sent_body' => HtmlFormatter::cleanHtml((string) ($message['body']['content'] ?? '')),
                ]);

                BulkMailLog::create([
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'action' => 'sent',
                    'metadata' => ['imported' => true, 'subject' => $message['subject'] ?? null],
                    'timestamp' => $sentAt,
                ]);

                $this->report(['imported' => $i + 1]);
            }

            return $campaign;
        });
    }

    /**
     * Whether a subject contains the text looked for — ignoring what makes
     * the same Arabic words differ unseen: hamza forms of alef (إ أ آ → ا),
     * ى/ي and ة/ه, diacritics and tatweel, right-to-left marks, Arabic-Indic
     * digits, letter case and repeated spaces.
     */
    public static function subjectMatches(string $subject, string $lookingFor): bool
    {
        $lookingFor = self::normalize($lookingFor);

        return $lookingFor === '' || str_contains(self::normalize($subject), $lookingFor);
    }

    /**
     * The addressee from the letter's salutation — the first line opening
     * with السادة / السيد / السيدة: what follows the slash or colon, up to
     * "ووكيله …" / "المحترم…" or a wide gap. Null when there is none.
     */
    public static function addressee(string $html): ?string
    {
        $text = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>~i', '
', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(' ', ' ', $text);

        foreach (preg_split('~\R~u', $text) ?: [] as $line) {
            $line = trim(preg_replace('~[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]~u', '', $line) ?? $line);

            if ($line === '') {
                continue;
            }

            if (! preg_match('~^(?:إلى\s+)?(?:السادة|السيد|السيدة)\s*[/:：]\s*(.+)$~u', $line, $m)) {
                continue;
            }

            // The name ends where the courtesy starts, or at a wide gap.
            $name = preg_split('~\s{3,}|	|\s+و?وكيل|\s+و?وكلاء|\s+المحترم~u', $m[1])[0] ?? '';
            // Unicode-aware: trim() works on bytes and would cut Arabic letters.
            $name = preg_replace('~^[\s\-–—,،.:/]+|[\s\-–—,،.:/]+$~u', '', $name) ?? $name;

            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * A header as text. The IMAP library can hand back an encoded header
     * as it came ("=?UTF-8?B?2KfZhNmC…?=") — which is how Outlook sends an
     * Arabic subject — so it is decoded here.
     */
    public static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if (! is_string($decoded) || $decoded === '') {
            $decoded = mb_decode_mimeheader($value);
        }

        return $decoded;
    }

    private static function normalize(string $text): string
    {
        $text = strtr($text, [
            'إ' => 'ا', 'أ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        // Diacritics, tatweel, and the invisible direction/joining marks.
        $text = preg_replace('~[\x{064B}-\x{065F}\x{0670}\x{0640}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]~u', '', $text) ?? $text;

        return trim(preg_replace('~\s+~u', ' ', mb_strtolower($text)) ?? '');
    }

    /**
     * An IMAP message in the shape the import reads (Microsoft Graph's).
     *
     * @return array<string, mixed>
     */
    public static function imapMessage(Message $message): array
    {
        $recipients = fn (string $header): array => array_map(
            fn (Address $address): array => ['emailAddress' => ['address' => $address->mail, 'name' => self::decodeHeader($address->personal)]],
            array_values(array_filter($message->get($header)?->all() ?? [], fn ($a) => $a instanceof Address)),
        );

        return [
            'internetMessageId' => trim((string) $message->getMessageId()) ?: null,
            'subject' => self::decodeHeader((string) $message->getSubject()),
            'sentDateTime' => $message->getDate()->toDate()->utc()->toIso8601String(),
            'body' => ['content' => $message->hasHTMLBody() ? $message->getHTMLBody() : nl2br(e($message->getTextBody()))],
            'toRecipients' => $recipients('to'),
            'ccRecipients' => $recipients('cc'),
        ];
    }

    /**
     * Every sent folder, in two passes so a folder full of attachments never
     * has to fit in memory: headers only for the date range, filtered by
     * subject (and each Message-ID once — an email can sit in two folders);
     * then each match fetched whole, one at a time, and let go once its body
     * is taken.
     *
     * @return list<array<string, mixed>>
     */
    private function fromImap(string $senderKey, string $subjectContains, CarbonInterface $from, CarbonInterface $to): array
    {
        // One email at a time can take a while on a big mailbox.
        @set_time_limit(0);

        try {
            $this->report([], __('Connecting to the mailbox…'));
            $folders = $this->sentFolder->sentFolders($senderKey);
        } catch (Throwable $e) {
            throw new RuntimeException(__('Could not read the Sent folder: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if ($folders === []) {
            throw new RuntimeException(__('No sent folder was found in this mailbox.'));
        }

        $names = array_map(fn ($folder): string => $folder->full_name, $folders);
        $this->report(['folders' => $names], __('Sent folders found: :names', ['names' => implode(', ', $names)]));

        $seen = [];
        $wanted = [];
        $scanned = 0;

        try {
            foreach ($folders as $folder) {
                $headers = $folder->query()
                    ->whereSince($from->copy()->startOfDay())
                    ->whereBefore($to->copy()->addDay()->startOfDay())
                    ->leaveUnread()
                    ->setFetchBody(false)
                    ->setFetchFlags(false)
                    ->get();

                $matched = 0;
                $inFolder = 0;

                foreach ($headers as $header) {
                    $scanned++;
                    $inFolder++;
                    $id = trim((string) $header->getMessageId());

                    if (! self::subjectMatches(self::decodeHeader((string) $header->getSubject()), $subjectContains) || ($id !== '' && isset($seen[$id]))) {
                        continue;
                    }

                    $seen[$id !== '' ? $id : uniqid('', true)] = true;
                    $wanted[] = [$folder, (int) $header->uid];
                    $matched++;
                }

                unset($headers);

                $this->report(['scanned' => $scanned, 'matched' => count($wanted)], __(':folder: :scanned emails in the dates, :matched matching.', [
                    'folder' => $folder->full_name,
                    'scanned' => $inFolder,
                    'matched' => $matched,
                ]));
            }

            if ($wanted !== []) {
                $this->report([], __('Reading :count matching emails…', ['count' => count($wanted)]));
            }

            $out = [];
            $attachmentsSaved = false;

            foreach ($wanted as $i => [$folder, $uid]) {
                $message = $folder->query()->leaveUnread()->setFetchFlags(false)->getMessageByUid($uid);
                $read = self::imapMessage($message);

                // They all carry the same attachment: kept once, from the
                // first email that has one, for the campaign.
                if (! $attachmentsSaved && $message->hasAttachments()) {
                    $read['attachments'] = $this->saveImapAttachments($message);
                    $attachmentsSaved = $read['attachments'] !== [];
                }

                $out[] = $read;
                unset($message);
                gc_collect_cycles();

                $this->report(['fetched' => $i + 1]);
            }
        } catch (Throwable $e) {
            throw new RuntimeException(__('Could not read the Sent folder: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromGraph(string $mailbox, string $subjectContains, CarbonInterface $from, CarbonInterface $to): array
    {
        $this->report(['folders' => ['Sent Items']], __('Reading Sent Items from Microsoft 365…'));

        $url = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/mailFolders/SentItems/messages';
        $query = [
            '$filter' => sprintf('sentDateTime ge %s and sentDateTime le %s', $from->copy()->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'), $to->copy()->endOfDay()->utc()->format('Y-m-d\TH:i:s\Z')),
            '$select' => 'id,subject,toRecipients,ccRecipients,sentDateTime,body,internetMessageId,hasAttachments',
            '$top' => 100,
        ];

        $messages = [];
        $scanned = 0;
        $attachmentsSaved = false;

        while ($url !== null) {
            $response = Http::withToken($this->graph->getAccessToken())->get($url, $query);

            if ($response->status() === 403) {
                throw new RuntimeException(__('Microsoft 365 refused to read this mailbox. Grant the Outlook app the Mail.Read application permission (with admin consent), then try again.'));
            }

            if ($response->failed()) {
                throw new RuntimeException(__('Could not read the Sent folder: :error', ['error' => $response->json('error.message') ?? $response->body()]));
            }

            $body = $response->json();

            foreach ($body['value'] ?? [] as $message) {
                $scanned++;

                if (self::subjectMatches((string) ($message['subject'] ?? ''), $subjectContains)) {
                    if (! $attachmentsSaved && ($message['hasAttachments'] ?? false)) {
                        $message['attachments'] = $this->saveGraphAttachments($mailbox, (string) $message['id']);
                        $attachmentsSaved = $message['attachments'] !== [];
                    }

                    $messages[] = $message;
                }
            }

            $this->report(['scanned' => $scanned, 'matched' => count($messages), 'fetched' => count($messages)]);

            // The next link carries the query itself; the key has a dot, so
            // read it off the array. Its query must go as null: an empty array
            // would replace the link's own query and fetch page one forever.
            $url = $body['@odata.nextLink'] ?? null;
            $query = null;
        }

        return $messages;
    }

    /**
     * An email's real attachments (not inline signature images), saved
     * where campaign attachments live.
     *
     * @return list<string> paths on the public disk
     */
    private function saveImapAttachments(Message $message): array
    {
        $files = [];

        foreach ($message->getAttachments() as $attachment) {
            if ($attachment->disposition === 'inline' && filled($attachment->id)) {
                continue;
            }

            $files[] = [self::decodeHeader((string) ($attachment->name ?: $attachment->filename)), (string) $attachment->content];
        }

        return $this->storeAttachments($files);
    }

    /**
     * @return list<string> paths on the public disk
     */
    private function saveGraphAttachments(string $mailbox, string $messageId): array
    {
        $response = Http::withToken($this->graph->getAccessToken())
            ->get('https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/messages/'.rawurlencode($messageId).'/attachments');

        $files = [];

        foreach ($response->successful() ? ($response->json()['value'] ?? []) : [] as $attachment) {
            if (($attachment['isInline'] ?? false) || ! isset($attachment['contentBytes'])) {
                continue;
            }

            $files[] = [(string) ($attachment['name'] ?? ''), (string) base64_decode($attachment['contentBytes'])];
        }

        return $this->storeAttachments($files);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $files  [name, content]
     * @return list<string>
     */
    private function storeAttachments(array $files): array
    {
        $directory = 'mail_attachments/imported/'.Str::lower(Str::random(10));
        $paths = [];

        foreach ($files as $i => [$name, $content]) {
            $name = trim(preg_replace('~[\\/:*?"<>|\x{0000}-\x{001F}]+~u', ' ', $name) ?? '') ?: 'attachment-'.($i + 1);
            $path = $directory.'/'.$name;

            Storage::disk('public')->put($path, $content);
            $paths[] = $path;
        }

        if ($paths !== []) {
            $this->report([], __('Attachment kept for the campaign: :names', ['names' => implode(', ', array_map('basename', $paths))]));
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function report(array $changes = [], ?string $step = null): void
    {
        if ($this->report !== null) {
            ($this->report)($changes, $step);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $recipients
     * @return list<string>
     */
    private static function addresses(array $recipients): array
    {
        return array_values(array_filter(array_map(
            fn (array $r): ?string => ($r['emailAddress']['address'] ?? null) ?: null,
            $recipients,
        )));
    }
}
