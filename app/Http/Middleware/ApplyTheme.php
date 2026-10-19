<?php

namespace App\Http\Middleware;

use App\Support\ThemeManager;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The theme, given to Filament for this request: its colours and its layout. It must run after the panel has
 * registered its own colours (the panel does at the start of the request), so the theme's come later and win;
 * and after the session has started, so it knows who is signed in. Filament reads both only when the page is
 * drawn.
 */
class ApplyTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        $themes = app(ThemeManager::class);

        if ($colors = $themes->colors()) {
            FilamentColor::register($colors);
        }

        if ($panel = Filament::getCurrentPanel()) {
            $themes->applyToPanel($panel);
        }

        return $next($request);
    }
}
