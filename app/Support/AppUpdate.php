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
    /**
     * The release tag of the checked-out commit — the highest vX.Y.Z tag
     * on it, if it has several — else config('license.app_version'). Read
     * straight from .git, without running git, so it's cheap enough for
     * every request.
     */
    public static function currentVersion(): string
    {
        if (config('license.version_from_git', true)) {
            $tagged = static::versionFromGit(base_path('.git'));

            if ($tagged !== null) {
                return $tagged;
            }
        }

        return (string) config('license.app_version');
    }

    public static function versionFromGit(string $gitDir): ?string
    {
        $head = static::resolveHead($gitDir);

        if ($head === null) {
            return null;
        }

        $versions = [];

        foreach (static::tagRefs($gitDir) as $tag => $commit) {
            if ($commit === $head && preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $match) === 1) {
                $versions[] = $match[1];
            }
        }

        usort($versions, fn (string $a, string $b): int => version_compare($b, $a));

        return $versions[0] ?? null;
    }

    /**
     * The commit hash HEAD points at: detached (a tag checked out by the
     * updater) or through a branch (a fresh clone on `main`).
     */
    private static function resolveHead(string $gitDir): ?string
    {
        $head = trim((string) @file_get_contents($gitDir.'/HEAD'));

        if (! str_starts_with($head, 'ref: ')) {
            return preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? $head : null;
        }

        $ref = substr($head, 5);
        $loose = trim((string) @file_get_contents($gitDir.'/'.$ref));

        if ($loose !== '') {
            return $loose;
        }

        foreach (static::packedRefs($gitDir) as $name => $commit) {
            if ($name === $ref) {
                return $commit;
            }
        }

        return null;
    }

    /**
     * Every tag name => the commit it points at. Annotated tags only
     * resolve through packed-refs (whose "^" lines give the commit); a
     * loose annotated tag holds a tag-object hash that simply won't match.
     *
     * @return array<string, string>
     */
    private static function tagRefs(string $gitDir): array
    {
        $tags = [];

        foreach (static::packedRefs($gitDir) as $name => $commit) {
            if (str_starts_with($name, 'refs/tags/')) {
                $tags[substr($name, 10)] = $commit;
            }
        }

        foreach (glob($gitDir.'/refs/tags/*') ?: [] as $file) {
            $tags[basename($file)] = trim((string) @file_get_contents($file));
        }

        return $tags;
    }

    /**
     * @return array<string, string> ref name => commit (peeled for annotated tags)
     */
    private static function packedRefs(string $gitDir): array
    {
        $refs = [];
        $last = null;

        foreach (@file($gitDir.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, '^') && $last !== null) {
                $refs[$last] = substr($line, 1);
            } elseif (preg_match('/^([0-9a-f]{40}) (\S+)$/', $line, $match) === 1) {
                $refs[$match[2]] = $match[1];
                $last = $match[2];
            }
        }

        return $refs;
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
