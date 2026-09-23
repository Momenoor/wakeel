<?php

namespace App\Http\Middleware;

use App\Models\License;
use App\Services\License\LicenseVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `RedirectToInstaller` has already let an installed app
 * through — appended to the `web` group (see `bootstrap/app.php`) AND
 * listed in each Filament panel's own middleware, which never runs the
 * `web` group: without the latter, the panels (i.e. the whole app) were
 * never license-checked at all. Locks the app down once the license has been invalid, or
 * simply un-checked, for longer than `license.grace_days` — see
 * `License::isValid()` for exactly what that means.
 */
class EnsureLicenseIsValid
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enforced() || $this->shouldBypass($request)) {
            return $next($request);
        }

        $license = License::current();

        // Picks up a license deleted/revoked on the server even where the
        // scheduled license:verify never runs (no cron on shared hosting).
        if ($license !== null) {
            app(LicenseVerifier::class)->verifyIfDue($license);
        }

        if ($license?->isValid() ?? false) {
            return $next($request);
        }

        return redirect()->route('license.show');
    }

    /**
     * Off by default in the `testing` environment — this middleware sits
     * on the whole `web` group, so without this every one of this app's
     * many existing feature tests that hits an HTTP route on an
     * "installed" test database (virtually all of them) would need to
     * seed a `License` row of its own just to keep working. Tests that
     * actually mean to exercise this middleware (see
     * `EnsureLicenseIsValidTest`) opt back in explicitly.
     */
    private function enforced(): bool
    {
        return ! app()->environment('testing') || config('license.force_enforcement_in_tests', false);
    }

    private function shouldBypass(Request $request): bool
    {
        return $request->routeIs('license.*')
            || $request->routeIs('installer.*')
            || $request->is('up')
            || $request->is('storage/*')
            || $request->is('build/*')
            || $request->is('livewire*');
    }
}
