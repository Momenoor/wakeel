<?php

namespace App\Services\MMS;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailLog;
use App\Models\BulkMailRecipient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Brings emails already sent from Outlook — outside the system — into a
 * bulk mail campaign, recorded as sent: one recipient per email, on the
 * date Outlook sent it. The campaign is created completed, so nothing is
 * ever sent again. An email already brought in (same message id) is
 * skipped, so running it twice adds nothing.
 *
 * Reads the mailbox's Sent Items through Microsoft Graph with the Outlook
 * app's credentials, which needs the Mail.Read application permission.
 */
class OutlookSentMailImporter
{
    public function __construct(
        private readonly OutlookCalendarService $graph,
    ) {}

    /**
     * Sent emails in a date range whose subject contains the given text.
     *
     * @return list<array<string, mixed>>
     */
    public function sentMessages(string $mailbox, string $subjectContains, CarbonInterface $from, CarbonInterface $to): array
    {
        $url = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/mailFolders/SentItems/messages';
        $query = [
            '$filter' => sprintf('sentDateTime ge %s and sentDateTime le %s', $from->copy()->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'), $to->copy()->endOfDay()->utc()->format('Y-m-d\TH:i:s\Z')),
            '$select' => 'subject,toRecipients,ccRecipients,sentDateTime,body,internetMessageId',
            '$orderby' => 'sentDateTime asc',
            '$top' => 100,
        ];

        $messages = [];

        while ($url !== null) {
            $response = Http::withToken($this->graph->getAccessToken())->get($url, $query);

            if ($response->status() === 403) {
                throw new RuntimeException(__('Microsoft 365 refused to read this mailbox. Grant the Outlook app the Mail.Read application permission (with admin consent), then try again.'));
            }

            if ($response->failed()) {
                throw new RuntimeException(__('Could not read Sent Items: :error', ['error' => $response->json('error.message') ?? $response->body()]));
            }

            $body = $response->json();

            foreach ($body['value'] ?? [] as $message) {
                if (mb_stripos((string) ($message['subject'] ?? ''), $subjectContains) !== false) {
                    $messages[] = $message;
                }
            }

            // The next link carries the query itself; the key has a dot, so
            // read it off the array, not with json()'s dot notation. Its query
            // must go as null: an empty array would replace the link's own
            // query string and fetch the first page again, forever.
            $url = $body['@odata.nextLink'] ?? null;
            $query = null;
        }

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
                'body' => (string) ($first['body']['content'] ?? ''),
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
                    'name' => $to[0]['emailAddress']['name'] ?? null,
                    'cc_emails' => self::addresses($message['ccRecipients'] ?? []) ?: null,
                    'status' => BulkMailRecipientStatus::Sent,
                    'sent_at' => $sentAt,
                    'message_id' => $message['internetMessageId'] ?? null,
                    'attempt_count' => 1,
                ]);

                BulkMailLog::create([
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'action' => 'sent',
                    'metadata' => ['imported_from' => 'outlook', 'subject' => $message['subject'] ?? null],
                    'timestamp' => $sentAt,
                ]);
            }

            return $campaign;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $recipients
     * @return list<string>
     */
    private static function addresses(array $recipients): array
    {
        return array_values(array_filter(array_map(
            fn (array $r): ?string => $r['emailAddress']['address'] ?? null,
            $recipients,
        )));
    }
}
