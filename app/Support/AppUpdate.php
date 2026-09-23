<?php

namespace App\Support;

use App\Models\License;
use BezhanSalleh\FilamentShield\Support\Utils;
use Throwable;

/**
 * This installation's version against the newest published release, as
 * last reported by the license server on a check-in (see LicenseVerifier).
 */
class AppUpdate
{
    public static function currentVersion(): string
    {
        return (string) config('license.app_version');
    }

    public static function latestVersion(): ?string
    {
        return once(function (): ?string {
            try {
                return License::current()?->latest_version;
            } catch (Throwable) {
                return null; // Not installed yet.
            }
        });
    }

    public static function available(): bool
    {
        $latest = static::latestVersion();

        return $latest !== null && version_compare($latest, static::currentVersion(), '>');
    }

    /**
     * Updating replaces the application's code — super admins only.
     */
    public static function canManage(): bool
    {
        return auth()->user()?->hasRole(Utils::getSuperAdminName()) ?? false;
    }
}
