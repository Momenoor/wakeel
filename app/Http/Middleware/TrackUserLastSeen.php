<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps the authenticated user's last_seen_at so the chat widget can show
 * an online/offline dot. Runs on panel page loads and, as persistent
 * middleware, on their Livewire polling requests; written at most once
 * every 10 seconds per user.
 */
class TrackUserLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subSeconds(10)))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
