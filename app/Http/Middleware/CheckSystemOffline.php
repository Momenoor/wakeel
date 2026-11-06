<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\Updater\Updater;
use BezhanSalleh\FilamentShield\Support\Utils;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class CheckSystemOffline
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. If system is online, allow everything
        if (! Setting::isOffline()) {
            return $next($request);
        }
        // A page's own requests (Livewire): the sign-in page's, and the super admin's — not those of a page someone
        // else had open when the system went offline.
        if ($request->is('livewire/*') || $request->hasHeader('X-Livewire')) {
            if (! $request->user() || self::mayWork($request->user())) {
                return $next($request);
            }

            return response()->json(['message' => Setting::getOfflineMessage(), 'status' => 'offline'], 503);
        }
        // 2. Allow static assets, health checks, maintenance page & Livewire core assets
        if (
            $request->is('up') ||
            $request->is('system-down*') ||
            $request->routeIs('system-down*') ||
            $request->is('livewire/*') ||
            $request->is('css/*') ||
            $request->is('js/*') ||
            $request->is('images/*') ||
            $request->is('fonts/*')
        ) {
            return $next($request);
        }

        // 3. Allow login & logout endpoints (GET & POST)
        if (
            $request->is('mms/login*') ||
            $request->is('mms/logout*') ||
            $request->is('pms/login*') ||
            $request->is('pms/logout*') ||
            $request->routeIs('filament.*.auth.login')

        ) {
            return $next($request);
        }

        $user = $request->user();

        // 4. Signed in: the super admin works on, when allowed; no one else — an admin neither.
        if ($user && self::mayWork($user)) {
            return $next($request);
        }

        // 5. If user is unauthenticated AND trying to access a Filament panel, send to login
        if (! $user && ($request->is('mms*') || $request->is('pms*') || $request->routeIs('filament.*'))) {
            $loginRoute = $request->is('pms*') ? 'filament.pms.auth.login' : 'filament.mms.auth.login';

            // A panel whose module isn't installed has no login route —
            // fall back to the default panel's.
            return Route::has($loginRoute)
                ? response()->redirectToRoute($loginRoute)
                : redirect()->to(Filament::getDefaultPanel()->getLoginUrl());
        }

        // 6. Everyone else (unauthenticated visitors or non-admin users) gets redirected to system-down
        $message = Setting::getOfflineMessage();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'status' => 'offline',
            ], 503);
        }

        return response()->redirectToRoute('system-down')->with('maintenance_message', $message);
    }

    /**
     * Who may use the system while it is offline: the super admin, when "Allow the super admin" is on — and, while an
     * update is running (it is what took the system offline), whoever may run updates, so the page driving its steps
     * is never shut out halfway.
     */
    private static function mayWork(mixed $user): bool
    {
        if (Setting::get(Updater::STATE_KEY) !== null && method_exists($user, 'can') && $user->can('View:SystemUpdates')) {
            return true;
        }

        return (bool) Setting::get('offline_allow_admins', true)
            && method_exists($user, 'hasRole')
            && $user->hasRole(Utils::getSuperAdminName());
    }
}
