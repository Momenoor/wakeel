<?php

namespace App\Services\License;

use App\Models\License;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Re-checks this installation's license against the license server and
 * records the answer on the `License` row — shared by the scheduled
 * `license:verify` command and `EnsureLicenseIsValid`.
 *
 * The middleware call is what makes a license deleted, revoked or suspended
 * on the server take effect without a cron job: shared hosting often never
 * runs the scheduler at all, which used to leave a dead license working
 * indefinitely.
 */
class LicenseVerifier
{
    public function __construct(
        private readonly LicenseClient $client,
    ) {}

    /**
     * @return array{valid: bool, reason: string|null, expires_at: string|null, plan: string|null, latest_version: string|null, release_notes: string|null, released_at: string|null}
     */
    public function verify(License $license): array
    {
        $result = $this->client->verify($license->key, $license->fingerprint);

        $license->last_checked_at = now();

        if ($result['valid']) {
            $license->status = 'active';
            $license->plan = $result['plan'] ?? $license->plan;
            $license->expires_at = $result['expires_at'] ? Carbon::parse($result['expires_at']) : null;
            $license->last_valid_at = now();
            $license->latest_version = $result['latest_version'];
            $license->latest_release_notes = $result['release_notes'];
            $license->latest_released_at = $result['released_at'] ? Carbon::parse($result['released_at']) : null;
        } elseif (in_array($result['reason'], ['suspended', 'revoked', 'expired', 'not_found', 'not_activated'], true)) {
            // The server actively said no — unlike a network error, this
            // is authoritative and shouldn't wait out the grace period.
            $license->status = $result['reason'] === 'not_found' || $result['reason'] === 'not_activated'
                ? 'revoked'
                : $result['reason'];
        }

        $license->save();

        return $result;
    }

    /**
     * verify(), at most once per `license.check_interval_minutes` (hourly
     * by default; 0 means every request). A network failure changes
     * nothing but last_checked_at: the app keeps running on the grace
     * period, and doesn't retry until the next interval.
     */
    public function verifyIfDue(License $license): void
    {
        $interval = (int) config('license.check_interval_minutes', 60);

        if ($interval > 0 && $license->last_checked_at !== null && $license->last_checked_at->gt(now()->subMinutes($interval))) {
            return;
        }

        try {
            $lock = Cache::lock('license:verify', 30);

            if (! $lock->get()) {
                return; // Another request is already checking.
            }
        } catch (Throwable) {
            $lock = null; // Cache store without locks — just check.
        }

        try {
            $this->verify($license);
        } finally {
            $lock?->release();
        }
    }
}
