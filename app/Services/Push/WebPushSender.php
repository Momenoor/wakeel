<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Support\Branding;
use GuzzleHttp\Client;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Web Push: a notification delivered by the browser's own push service to
 * every browser and phone the user allowed — shown even when no Wakeel tab
 * is open. Signed with the VAPID keys (see VapidKeys). Subscriptions a
 * push service reports as gone are removed.
 */
class WebPushSender
{
    /**
     * @param  ClientInterface|null  $client  the HTTP client for the push services (tests pass a mock)
     */
    public function __construct(private readonly ?ClientInterface $client = null) {}

    public static function isConfigured(): bool
    {
        return VapidKeys::publicKey() !== null;
    }

    /**
     * What a system (database) notification looks like as a push: its
     * title, plain-text body and first action's link. One tag per
     * notification, so a tab already showing it replaces rather than
     * doubles it.
     *
     * @return array{id: string, title: string, body: string, url: string, tag: string, icon: string}
     */
    public static function payloadFor(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;

        return [
            'id' => (string) $notification->id,
            'title' => (string) ($data['title'] ?? __('notifications.new')),
            'body' => Str::limit(trim(html_entity_decode(strip_tags((string) ($data['body'] ?? '')))), 200),
            'url' => (string) (collect($data['actions'] ?? [])->pluck('url')->filter()->first() ?? rtrim((string) config('app.url'), '/').'/'),
            'tag' => 'wakeel-'.$notification->id,
            'icon' => Branding::faviconUrl(),
        ];
    }

    /**
     * Sends to every subscription the user has; returns how many took it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendToUser(int $userId, array $payload): int
    {
        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $keys = VapidKeys::get();

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) (config('services.webpush.subject') ?: config('app.url')),
                'publicKey' => $keys['public'],
                'privateKey' => $keys['private'],
            ],
        ], ['TTL' => 3600, 'urgency' => 'high'], $this->client ?? new Client(['timeout' => 15]));

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), $json);
        }

        $sent = 0;

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;

                continue;
            }

            // Unsubscribed, or the browser was reset: never deliverable again.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint($report->getEndpoint()))->delete();

                continue;
            }

            Log::warning('Web push failed: '.$report->getReason(), ['user_id' => $userId]);
        }

        return $sent;
    }
}
