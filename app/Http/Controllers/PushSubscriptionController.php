<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where the page records (and forgets) the browser it runs in as a Web Push
 * destination for the signed-in user. A browser signed in by someone else
 * later moves to that person.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
            'device' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        $userAgent = mb_substr((string) $request->userAgent(), 0, 255);
        $device = $data['device'] ?? null;

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => $hash],
            [
                'user_id' => $request->user()->getKey(),
                'device_id' => $device,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'user_agent' => $userAgent,
            ],
        );

        if ($device !== null) {
            $this->forgetOlderOfThisBrowser($request->user()->getKey(), $device, $hash, $userAgent, $data['endpoint']);
        }

        return response()->noContent();
    }

    /**
     * One subscription a browser: its older ones gone — the push services went on delivering to an address a
     * browser had replaced (a new one after the move to another server, its data cleared), and each notification
     * came twice or more. Those this browser made under its number, for whoever was signed in then; and, from
     * before browsers had a number, the signed-in user's own from the same browser and push service — another
     * computer with the same browser among them subscribes again the next time it opens the system.
     */
    private function forgetOlderOfThisBrowser(int $userId, string $device, string $hash, string $userAgent, string $endpoint): void
    {
        $service = (string) parse_url($endpoint, PHP_URL_SCHEME).'://'.(string) parse_url($endpoint, PHP_URL_HOST).'/';

        PushSubscription::query()
            ->where('endpoint_hash', '!=', $hash)
            ->where(fn ($query) => $query
                ->where('device_id', $device)
                ->orWhere(fn ($query) => $query
                    ->whereNull('device_id')
                    ->where('user_id', $userId)
                    ->where('user_agent', $userAgent)
                    ->where('endpoint', 'like', str_replace(['%', '_'], ['\%', '\_'], $service).'%')))
            ->delete();
    }

    public function destroy(Request $request): Response
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushSubscription::where('user_id', $request->user()->getKey())
            ->where('endpoint_hash', PushSubscription::hashEndpoint($request->input('endpoint')))
            ->delete();

        return response()->noContent();
    }
}
