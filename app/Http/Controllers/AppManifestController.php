<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/**
 * What a browser reads to install Wakeel as an app (Windows, Mac, Android; an iPhone's Add to Home Screen too —
 * which iPhone requires before it delivers push notifications): its name, its own window, its icons in the sizes
 * browsers ask for (AppIconController), and shortcuts to Chat and Matters from the app's icon.
 */
class AppManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $root = rtrim((string) config('app.url'), '/').'/';
        $name = (string) Setting::get('app_name', config('app.name'));
        $version = AppIconController::version();
        $icon = fn (int $size, string $purpose): array => [
            'src' => route('app-icon', ['size' => $size, 'purpose' => $purpose]).'?v='.$version,
            'sizes' => $size.'x'.$size,
            'type' => 'image/png',
            'purpose' => $purpose,
        ];

        $shortcuts = array_values(array_filter([
            Route::has('filament.mms.pages.chat') ? ['name' => __('Chat'), 'url' => route('filament.mms.pages.chat')] : null,
            Route::has('filament.mms.resources.matters.index') ? ['name' => __('Matters'), 'url' => route('filament.mms.resources.matters.index')] : null,
        ]));

        return response()->json([
            'id' => $root,
            'name' => $name,
            'short_name' => $name,
            'description' => __('The office\'s system'),
            'start_url' => $root,
            'scope' => $root,
            'display' => 'standalone',
            'lang' => app()->getLocale(),
            'dir' => app()->getLocale() === 'ar' ? 'rtl' : 'ltr',
            'background_color' => '#ffffff',
            'theme_color' => '#1e3a8a',
            'icons' => [$icon(192, 'any'), $icon(512, 'any'), $icon(512, 'maskable')],
            'shortcuts' => array_map(fn (array $s): array => $s + ['icons' => [['src' => route('app-icon', ['size' => 192, 'purpose' => 'any']).'?v='.$version, 'sizes' => '192x192', 'type' => 'image/png']]], $shortcuts),
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            // Kept by the browser for a day: it was fetched again on every page,
            // one more request queueing for the server at each page load.
            'Cache-Control' => 'public, max-age=86400',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
