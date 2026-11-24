<?php

namespace App\Services\MMS;

use App\Enums\DeliveryStatus;
use App\Models\MatterEmail;
use App\Support\Integrations;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * What became of each email the system keeps — sent from it, written from Outlook, or received — as Microsoft
 * 365's message trace says (Microsoft Graph /admin/exchange/tracing/messageTraces, the application permission
 * ExchangeMessageTrace.Read.All): delivered, still on its way, failed, quarantined, taken for spam — at each address
 * it went to (a received one: at our mailbox), kept on the email (MatterEmail::delivery) with the worst of them for
 * the Emails tab (delivery_status). Read every fifteen minutes (mail:trace-delivery) while some are still to be
 * known, for the emails of Microsoft 365 mailboxes (one through another server has no trace there): a request a
 * mailbox and direction — what it sent by sender, what it received by recipient — its emails of the days since the
 * oldest still unknown matched by their Message-ID. Microsoft keeps a trace for 90 days but gives ten days at a
 * time; an email it has no trace of two days after it went is given up on (NONE).
 */
final class MessageTrace
{
    private const URL = 'https://graph.microsoft.com/v1.0/admin/exchange/tracing/messageTraces';

    /** Microsoft gives the traces of ten days at a time — older ones are not asked for. */
    public const DAYS = 10;

    /** An email with no trace this long after it went: not sent through Microsoft 365 — not looked for again. */
    private const GIVE_UP_HOURS = 48;

    /** Why an address failed, read for this many a run: Microsoft allows 100 requests in five minutes. */
    private const DETAILS_A_RUN = 20;

    /** Set while Microsoft refuses (the permission not given): not asked again until it ends. */
    private const REFUSED = 'message_trace_refused';

    public function __construct(private readonly OutlookCalendarService $graph) {}

    /** On in Settings → Integrations → Microsoft 365, the app registration filled in. */
    public static function enabled(): bool
    {
        return Integrations::microsoftOn() && Integrations::get(Integrations::MS_TRACE) === '1';
    }

    /**
     * The emails still to be known, each mailbox's read and their delivery kept.
     *
     * @return array{checked: int, updated: int}
     */
    public function run(): array
    {
        if (! self::enabled() || Cache::has(self::REFUSED)) {
            return ['checked' => 0, 'updated' => 0];
        }

        $pending = $this->pending();
        $updated = 0;
        $details = self::DETAILS_A_RUN;

        foreach ($pending->groupBy('sender_key') as $senderKey => $emails) {
            $address = $this->microsoftAddress((string) $senderKey);

            if ($address === null) {
                // Not a Microsoft 365 mailbox: nothing to find there.
                $updated += $this->settle($emails, collect(), $details);

                continue;
            }

            try {
                // Those it sent by their sender, those it received by their recipient: the mailbox each time.
                [$sent, $received] = $emails->partition(fn (MatterEmail $email): bool => $email->direction === MatterEmail::SENT);
                $traces = collect();

                foreach (['senderAddress' => $sent, 'recipientAddress' => $received] as $field => $some) {
                    if ($some->isNotEmpty()) {
                        $traces = $traces->union($this->traces($field, $address, Carbon::parse($some->min('at'))->subMinutes(5)));
                    }
                }
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'Graph 403') || str_contains($e->getMessage(), 'Graph 401')) {
                    // Said once, then left alone for six hours: every quarter of an hour would be the same error.
                    Cache::put(self::REFUSED, true, now()->addHours(6));
                    report(new RuntimeException('Microsoft 365 message trace refused: give the app registration the application permission ExchangeMessageTrace.Read.All (with admin consent), then turn it on again. '.$e->getMessage(), 0, $e));

                    return ['checked' => $pending->count(), 'updated' => $updated];
                }

                report($e);

                continue;
            }

            $updated += $this->settle($emails, $traces, $details);
        }

        return ['checked' => $pending->count(), 'updated' => $updated];
    }

    /**
     * Every email the system keeps of the last ten days — sent from it, written from Outlook, or received — a
     * couple of minutes old, whose delivery is not yet known at every address.
     *
     * @return Collection<int, MatterEmail>
     */
    private function pending(): Collection
    {
        return MatterEmail::query()
            ->whereNotNull('message_id')
            ->whereNotNull('sender_key')
            ->whereBetween('at', [now()->subDays(self::DAYS)->addHour(), now()->subMinutes(2)])
            ->where(fn ($query) => $query->whereNull('delivery_status')->orWhere('delivery_status', DeliveryStatus::PENDING->value))
            ->get(['id', 'direction', 'sender_key', 'message_id', 'to', 'at', 'delivery', 'delivery_status']);
    }

    /** The mailbox's address when it sends through Microsoft 365 (Graph, or Microsoft's SMTP) — else null. */
    private function microsoftAddress(string $senderKey): ?string
    {
        try {
            $sender = SenderMailer::sender($senderKey);
        } catch (Throwable) {
            return null;
        }

        $host = strtolower((string) ($sender['host'] ?? ''));
        $microsoft = ($sender['driver'] ?? null) === 'microsoft'
            || str_contains($host, 'office365.com') || str_contains($host, 'outlook.com');

        return $microsoft && filled($sender['address'] ?? null) ? strtolower((string) $sender['address']) : null;
    }

    /**
     * The mailbox's traces since then (ten days at most) — what it sent (senderAddress) or received
     * (recipientAddress) — by Message-ID: each a list of its addresses.
     *
     * @param  'senderAddress'|'recipientAddress'  $field
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function traces(string $field, string $address, Carbon $since): Collection
    {
        $start = $since->max(now()->subDays(self::DAYS)->addMinute())->utc()->format('Y-m-d\TH:i:s\Z');
        $end = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $url = self::URL.'?'.http_build_query([
            '$filter' => "{$field} eq '".str_replace("'", "''", $address)."' and receivedDateTime ge {$start} and receivedDateTime le {$end}",
            '$top' => 5000,
        ], '', '&', PHP_QUERY_RFC3986);

        $rows = collect();

        // Page after page (5,000 each), as long as Microsoft gives a next one.
        for ($page = 0; $url !== null && $page < 20; $page++) {
            $response = $this->get($url);
            $rows = $rows->concat((array) $response->json('value', []));
            $url = $response->json('@odata.nextLink');
        }

        return $rows->groupBy(fn (array $row): string => (string) MatterEmail::messageId((string) ($row['messageId'] ?? '')));
    }

    /**
     * Each email's addresses as their traces say; what failed with the reason Microsoft gives.
     *
     * @param  Collection<int, MatterEmail>  $emails
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $traces
     */
    private function settle(Collection $emails, Collection $traces, int &$details): int
    {
        $updated = 0;

        foreach ($emails as $email) {
            $rows = $traces->get((string) $email->message_id, collect());
            $delivery = (array) ($email->delivery ?? []);

            foreach ($rows as $row) {
                $status = DeliveryStatus::fromTrace($row['status'] ?? null);
                $recipient = strtolower((string) ($row['recipientAddress'] ?? ''));

                if ($status === null || $recipient === '') {
                    continue;
                }

                $entry = ['status' => $status->value, 'at' => $row['receivedDateTime'] ?? null, 'detail' => $delivery[$recipient]['detail'] ?? null];

                // Why it did not get there, in Microsoft's words — once.
                if ($status->isProblem() && $entry['detail'] === null && $details > 0 && filled($row['id'] ?? null)) {
                    $details--;
                    $entry['detail'] = $this->reason((string) $row['id'], $recipient);
                }

                $delivery[$recipient] = $entry;
            }

            $summary = $this->worst($delivery)
                ?? (Carbon::parse($email->at)->lt(now()->subHours(self::GIVE_UP_HOURS)) ? DeliveryStatus::NONE : null);

            $email->forceFill([
                'delivery' => $delivery === [] ? null : $delivery,
                'delivery_status' => $summary?->value,
                'delivery_checked_at' => now(),
            ])->saveQuietly();

            $updated += $rows->isNotEmpty() || $summary === DeliveryStatus::NONE ? 1 : 0;
        }

        return $updated;
    }

    /** @param  array<string, array<string, mixed>>  $delivery */
    private function worst(array $delivery): ?DeliveryStatus
    {
        return collect($delivery)
            ->map(fn (array $entry): ?DeliveryStatus => DeliveryStatus::tryFrom((string) ($entry['status'] ?? '')))
            ->filter()
            ->sortByDesc(fn (DeliveryStatus $status): int => $status->weight())
            ->first();
    }

    /** Microsoft's last word on what happened at the address: "550 5.1.1 … not found", say. */
    private function reason(string $traceId, string $recipient): ?string
    {
        try {
            $url = self::URL.'/'.rawurlencode($traceId)."/getDetailsByRecipient(recipientAddress='".rawurlencode(str_replace("'", "''", $recipient))."')";
            $events = collect((array) $this->get($url)->json('value', []));
            $last = $events->sortBy('dateTime')->last(fn (array $event): bool => filled($event['description'] ?? null));

            return $last ? Str::limit(trim((string) $last['description']), 500) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function get(string $url): Response
    {
        $response = Http::withToken($this->graph->getAccessToken())->timeout(60)->get($url);

        if ($response->failed()) {
            throw new RuntimeException('Microsoft Graph '.$response->status().': '.($response->json('error.message') ?? Str::limit($response->body(), 300)));
        }

        return $response;
    }
}
