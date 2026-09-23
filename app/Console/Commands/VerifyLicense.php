<?php

namespace App\Console\Commands;

use App\Models\License;
use App\Services\License\LicenseVerifier;
use Illuminate\Console\Command;

/**
 * Scheduled (see `bootstrap/app.php`) to re-check this installation's
 * license periodically. A network failure or a down license server
 * leaves `last_valid_at` stale rather than marking the license invalid
 * outright — that staleness, checked against `license.grace_days` in
 * `License::isValid()`, is the whole mechanism behind the offline grace
 * period `EnsureLicenseIsValid` enforces.
 */
class VerifyLicense extends Command
{
    protected $signature = 'license:verify';

    protected $description = 'Re-check this installation\'s license against the license server.';

    public function handle(LicenseVerifier $verifier): int
    {
        $license = License::current();

        if (! $license) {
            $this->info('No license on file — nothing to verify.');

            return self::SUCCESS;
        }

        $result = $verifier->verify($license);

        $this->info($result['valid'] ? 'License valid.' : "License check failed: {$result['reason']}");

        return self::SUCCESS;
    }
}
