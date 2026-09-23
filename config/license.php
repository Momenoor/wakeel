<?php

return [

    /*
    |--------------------------------------------------------------------------
    | License Server
    |--------------------------------------------------------------------------
    |
    | Where this installation activates and periodically re-verifies its
    | license key. Set by the installer wizard's License step, but always
    | overridable in `.env` for a self-hosted license server.
    |
    */

    'server_url' => env('LICENSE_SERVER_URL', 'https://license.jpaemirates.com'),

    /*
    |--------------------------------------------------------------------------
    | Installation ID
    |--------------------------------------------------------------------------
    |
    | This installation's own stable identity — generated once (by the
    | installer's License step) and written to `.env` as INSTALLATION_ID.
    | Sent as the `fingerprint` on every activate/verify call, so the
    | license server can tell this install apart from any other sharing
    | the same key, up to the license's own `max_activations`.
    |
    */

    'installation_id' => env('INSTALLATION_ID'),

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | The running version is the release tag (vX.Y.Z) of the checked-out
    | commit — tagging IS releasing, there is nothing to bump here (see
    | App\Support\AppUpdate::currentVersion()). `app_version` is only the
    | fallback for a checkout that isn't on a tagged commit, e.g. a
    | development machine on `main`. `version_from_git` is off in tests,
    | which run inside this repository's own (tagged) checkout.
    |
    */

    'app_version' => '1.0.7',

    'version_from_git' => env('LICENSE_VERSION_FROM_GIT', true),

    /*
    |--------------------------------------------------------------------------
    | Offline Grace Period
    |--------------------------------------------------------------------------
    |
    | How long this app keeps running without a successful check-in
    | before `EnsureLicenseIsValid` locks it down. Covers ordinary
    | connectivity gaps to the license server without punishing an
    | otherwise-valid install for a temporary outage.
    |
    */

    'grace_days' => env('LICENSE_GRACE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Check Interval
    |--------------------------------------------------------------------------
    |
    | How often `EnsureLicenseIsValid` re-checks the license with the
    | server itself, in minutes — so a license deleted or revoked there
    | takes effect even when the scheduled `license:verify` never runs
    | (no cron job on the server): the first page request after the
    | interval has passed asks the server. 0 checks on every page request.
    |
    */

    'check_interval_minutes' => env('LICENSE_CHECK_INTERVAL_MINUTES', 60),

];
