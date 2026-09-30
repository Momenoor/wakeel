<?php

namespace App\Services\MMS;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Helpers\HtmlFormatter;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailLog;
use App\Models\BulkMailRecipient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Message;

/**
 * Brings emails already sent by hand — outside the system — into a bulk
 * mail campaign, recorded as sent: one recipient per email, on the date it
 * was sent, keeping that email's own subject and body (they differ — the
 * company name …) for its PDF and view. The campaign is created completed, so nothing is ever sent
 * again. An email already brought in (same Message-ID) is skipped, so
 * running it twice adds nothing.
 *
 * Reads the sender mailbox's Sent folder: over IMAP for a cPanel (SMTP)
 * sender — the same connection SentFolder copies sent mail into — or
 * through Microsoft Graph for a Microsoft 365 sender (which needs the
 * Mail.Read application permission).
 */
class SentMailImporter
{
    public function __construct(
        private readonly SentFolder $sentFolder,
        private readonly OutlookCalendarService $graph,
    ) {}

    /**
     * Emails sent from the sender's mailbox in a date range whose subject
     * contains the given text, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function sentMessages(string $senderKey, string $subjectContains, CarbonInterface $from, CarbonInterface $to): array
    {
        $sender = SenderMailer::sender($senderKey);

        $messages = SenderMailer::isMicrosoft($sender)
            ? $this->fromGraph((string) $sender['address'], $from, $to)
            : $this->fromImap($senderKey, $from, $to);

        $messages = array_values(array_filter(
            $messages,
            fn (array $message): bool => mb_stripos((string) ($message['subject'] ?? ''), $subjectContains) !== false,
        ));

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

        if ($messages === []) {
            throw new RuntimeException(__('No new sent emails to bring in — none matched, or they were brought in already.'));
        }

        return DB::transaction(function () use ($name, $senderKey, $messages, $userId): BulkMailCampaign {
            $first = $messages[0];

            $campaign = BulkMailCampaign::create([
                'name' => $name,
                'subject' => (string) ($first['subject'] ?? $name),
                'body' => HtmlFormatter::cleanHtml((string) ($first['body']['content'] ?? '')),
                'from_sender_key' => $senderKey,
                'status' => BulkMailCampaignStatus::Completed,
                'total_recipients' => count($messages),
                'sent_count' => count($messages),
                'failed_count' => 0,
                'created_by' => $userId,
            ]);

            foreach ($messages as $message) {
                $to = $message['toRecipients'] ?? [];
                $sentAt = Carbon::parse($message['sentDateTime'])->setTimezone(config('app.timezone'));

                $recipient = BulkMailRecipient::create([
                    'campaign_id' => $campaign->id,
                    'email' => self::addresses($to),
                    'name' => ($to[0]['emailAddress']['name'] ?? null) ?: null,
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
            }

            return $campaign;
        });
    }

    /**
     * An IMAP message in the shape the import reads (Microsoft Graph's).
     *
     * @return array<string, mixed>
     */
    public static function imapMessage(Message $message): array
    {
        $recipients = fn (string $header): array => array_map(
            fn (Address $address): array => ['emailAddress' => ['address' => $address->mail, 'name' => $address->personal]],
            array_values(array_filter($message->get($header)?->all() ?? [], fn ($a) => $a instanceof Address)),
        );

        return [
            'internetMessageId' => trim((string) $message->getMessageId()) ?: null,
            'subject' => (string) $message->getSubject(),
            'sentDateTime' => $message->getDate()->toDate()->utc()->toIso8601String(),
            'body' => ['content' => $message->hasHTMLBody() ? $message->getHTMLBody() : nl2br(e($message->getTextBody()))],
            'toRecipients' => $recipients('to'),
            'ccRecipients' => $recipients('cc'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromImap(string $senderKey, CarbonInterface $from, CarbonInterface $to): array
    {
        try {
            $messages = $this->sentFolder->folder($senderKey)
                ->query()
                ->whereSince($from->copy()->startOfDay())
                ->whereBefore($to->copy()->addDay()->startOfDay())
                ->leaveUnread()
                ->get();
        } catch (Throwable $e) {
            throw new RuntimeException(__('Could not read the Sent folder: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        $out = [];

        foreach ($messages as $message) {
            $out[] = self::imapMessage($message);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromGraph(string $mailbox, CarbonInterface $from, CarbonInterface $to): array
    {
        $url = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/mailFolders/SentItems/messages';
        $query = [
            '$filter' => sprintf('sentDateTime ge %s and sentDateTime le %s', $from->copy()->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'), $to->copy()->endOfDay()->utc()->format('Y-m-d\TH:i:s\Z')),
            '$select' => 'subject,toRecipients,ccRecipients,sentDateTime,body,internetMessageId',
            '$top' => 100,
        ];

        $messages = [];

        while ($url !== null) {
            $response = Http::withToken($this->graph->getAccessToken())->get($url, $query);

            if ($response->status() === 403) {
                throw new RuntimeException(__('Microsoft 365 refused to read this mailbox. Grant the Outlook app the Mail.Read application permission (with admin consent), then try again.'));
            }

            if ($response->failed()) {
                throw new RuntimeException(__('Could not read the Sent folder: :error', ['error' => $response->json('error.message') ?? $response->body()]));
            }

            $body = $response->json();
            array_push($messages, ...($body['value'] ?? []));

            // The next link carries the query itself; the key has a dot, so
            // read it off the array. Its query must go as null: an empty array
            // would replace the link's own query and fetch page one forever.
            $url = $body['@odata.nextLink'] ?? null;
            $query = null;
        }

        return $messages;
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
