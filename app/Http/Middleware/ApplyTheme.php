<?php

namespace App\Http\Middleware;

use App\Support\ThemeManager;
use Closure;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The theme's colours, given to Filament for this request. It must run after the panel has registered its own
 * colours (the panel does at the start of the request), so the theme's come later and win; and after the session
 * has started, so it knows who is signed in. Filament reads the registry only when the page is drawn.
 */
class ApplyTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($colors = app(ThemeManager::class)->colors()) {
            FilamentColor::register($colors);
        }

        return $next($request);
    }
}
