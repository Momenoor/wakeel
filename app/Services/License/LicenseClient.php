<?php

namespace App\Services\License;

use App\Support\AppUpdate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The only place this app talks to the license server — the installer's
 * License step calls `activate()` once; `LicenseVerifier` calls `verify()`
 * on every check-in. Both return the same shape regardless of
 * success/failure/network error, so callers never need a try/catch of
 * their own.
 *
 * A valid answer also carries the newest published release — that is how
 * the app learns about updates, with no request of its own.
 *
 * @phpstan-type LicenseResult array{valid: bool, reason: string|null, expires_at: string|null, plan: string|null, latest_version: string|null, release_notes: string|null, released_at: string|null}
 */
class LicenseClient
{
    /**
     * @return LicenseResult
     */
    public function activate(string $licenseKey, string $fingerprint, string $domain): array
    {
        return $this->call('activate', [
            'license_key' => $licenseKey,
            'fingerprint' => $fingerprint,
            'domain' => $domain,
            'app_version' => AppUpdate::currentVersion(),
        ]);
    }

    /**
     * @return LicenseResult
     */
    public function verify(string $licenseKey, string $fingerprint): array
    {
        return $this->call('verify', [
            'license_key' => $licenseKey,
            'fingerprint' => $fingerprint,
            // Lets the server track which version each installation runs.
            'app_version' => AppUpdate::currentVersion(),
        ]);
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return LicenseResult
     */
    private function call(string $endpoint, array $payload): array
    {
        $failure = 'network_error';

        // Each address of the license server in turn (license.jpaemirates.com, then .website): one that cannot be
        // reached, or answers with an error page rather than the license server's answer (a 404 from a host the
        // name still points to, a 500), gives way to the next. A real answer — a key not found too — is final.
        foreach (self::servers() as $server) {
            try {
                // Also runs inside a page request (EnsureLicenseIsValid) — an
                // unreachable server must fail fast, not stall the page.
                $response = Http::connectTimeout(3)
                    ->timeout(10)
                    ->baseUrl($server.'/api/v1/license')
                    ->post($endpoint, $payload);
            } catch (Throwable) {
                $failure = 'network_error';

                continue;
            }

            $body = $response->json();

            if (! is_array($body) || ! array_key_exists('valid', $body)) {
                $failure = 'server_error';

                continue;
            }

            self::remember($server);

            return [
                'valid' => (bool) $body['valid'],
                'reason' => $body['reason'] ?? ($response->successful() ? null : 'server_error'),
                'expires_at' => $body['expires_at'] ?? null,
                'plan' => $body['plan'] ?? null,
                'latest_version' => $body['latest_version'] ?? null,
                'release_notes' => $body['release_notes'] ?? null,
                'released_at' => $body['released_at'] ?? null,
            ];
        }

        return [
            'valid' => false,
            'reason' => $failure,
            'expires_at' => null,
            'plan' => null,
            'latest_version' => null,
            'release_notes' => null,
            'released_at' => null,
        ];
    }

    /** Where the last answer came from: asked first next time. */
    private const ANSWERED = 'license.server_answered';

    /**
     * The license server's addresses, the one that answered last first.
     *
     * @return list<string>
     */
    public static function servers(): array
    {
        $servers = array_values(array_unique(array_filter(array_map(
            fn (string $url): string => rtrim(trim($url), '/'),
            [(string) config('license.server_url'), ...explode(',', (string) config('license.fallback_urls'))],
        ))));

        try {
            $answered = Cache::get(self::ANSWERED);
        } catch (Throwable) {
            $answered = null;
        }

        if (is_string($answered) && in_array($answered, $servers, true)) {
            $servers = [$answered, ...array_values(array_diff($servers, [$answered]))];
        }

        return $servers;
    }

    private static function remember(string $server): void
    {
        try {
            Cache::put(self::ANSWERED, $server, now()->addDay());
        } catch (Throwable) {
            // No cache: the usual order next time.
        }
    }
}
