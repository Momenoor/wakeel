<?php

namespace App\Services\License;

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
            'app_version' => config('license.app_version'),
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
            'app_version' => config('license.app_version'),
        ]);
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return LicenseResult
     */
    private function call(string $endpoint, array $payload): array
    {
        try {
            // Also runs inside a page request (EnsureLicenseIsValid) — an
            // unreachable server must fail fast, not stall the page.
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->baseUrl(rtrim((string) config('license.server_url'), '/').'/api/v1/license')
                ->post($endpoint, $payload);

            $body = $response->json();

            return [
                'valid' => (bool) ($body['valid'] ?? false),
                'reason' => $body['reason'] ?? ($response->successful() ? null : 'server_error'),
                'expires_at' => $body['expires_at'] ?? null,
                'plan' => $body['plan'] ?? null,
                'latest_version' => $body['latest_version'] ?? null,
                'release_notes' => $body['release_notes'] ?? null,
                'released_at' => $body['released_at'] ?? null,
            ];
        } catch (Throwable) {
            return [
                'valid' => false,
                'reason' => 'network_error',
                'expires_at' => null,
                'plan' => null,
                'latest_version' => null,
                'release_notes' => null,
                'released_at' => null,
            ];
        }
    }
}
