<?php

namespace App\Support\Guide;

use Illuminate\Support\Facades\File;

/**
 * The User Guide's content: one index per language (resources/guide/{locale}/index.php)
 * listing modules, and one file per module with its actions, each with the
 * roles that can do it and its step-by-step screenshots
 * (public/guide/{locale}/{module}/{shot}.webp).
 *
 * A language without a guide yet falls back to Arabic, the first one written.
 */
final class Guide
{
    public const FALLBACK = 'ar';

    public static function locale(): string
    {
        $locale = app()->getLocale();

        return File::exists(resource_path("guide/{$locale}/index.php")) ? $locale : self::FALLBACK;
    }

    /**
     * @return list<array{group: string, id: string, panel: string, icon: string, title: string, summary: string}>
     */
    public static function modules(?string $panel = null): array
    {
        $modules = require resource_path('guide/'.self::locale().'/index.php');

        return array_values(array_filter(
            $modules['modules'],
            fn (array $m): bool => File::exists(resource_path('guide/'.self::locale()."/{$m['id']}.php"))
                && ($panel === null || in_array($m['panel'], [$panel, 'shared'], true)),
        ));
    }

    /**
     * @return array{title: string, intro: string, actions: list<array<string, mixed>>}|null
     */
    public static function module(string $id): ?array
    {
        $file = resource_path('guide/'.self::locale()."/{$id}.php");

        return preg_match('/^[a-z0-9-]+$/', $id) && File::exists($file) ? require $file : null;
    }

    /**
     * A step's screenshot: its name in the module's own folder, or "folder/name"
     * for one taken in another module's folder (shared by several guides).
     */
    public static function shotUrl(string $module, string $shot): string
    {
        [$module, $shot] = str_contains($shot, '/') ? explode('/', $shot, 2) : [$module, $shot];

        return asset('guide/'.self::locale()."/{$module}/{$shot}.webp");
    }
}
