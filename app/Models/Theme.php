<?php

namespace App\Models;

use App\Enums\ThemeDensity;
use App\Enums\ThemeRadius;
use App\Enums\ThemeSidebar;
use App\Support\ThemeOptions;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How the system looks: colours, fonts, corners, spacing, the sidebar, and the options App\Support\ThemeOptions
 * lists (navigation, light/dark, surfaces, logos …). Applied by App\Support\ThemeManager to every page.
 */
#[Fillable('name', 'colors', 'latin_font_id', 'arabic_font_id', 'radius', 'density', 'sidebar', 'options')]
class Theme extends Model
{
    /** The colours a theme can set: Filament's own names, and a secondary one for the office's own use. */
    public const COLORS = ['primary', 'secondary', 'gray', 'info', 'success', 'warning', 'danger'];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'options' => 'array',
            'radius' => ThemeRadius::class,
            'density' => ThemeDensity::class,
            'sidebar' => ThemeSidebar::class,
        ];
    }

    public function latinFont(): BelongsTo
    {
        return $this->belongsTo(LetterFont::class, 'latin_font_id');
    }

    public function arabicFont(): BelongsTo
    {
        return $this->belongsTo(LetterFont::class, 'arabic_font_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The colours it sets, as Filament takes them: one of its palettes (all the shades) or a hex value (Filament
     * makes the shades). One left empty keeps Filament's.
     *
     * @return array<string, array<int, string>|string>
     */
    public function setColors(): array
    {
        return collect($this->colors ?? [])
            ->only(self::COLORS)
            ->map(fn ($value) => match (true) {
                ! is_string($value) => null,
                isset(Color::all()[$value]) => Color::all()[$value],
                preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 => $value,
                default => null,
            })
            ->filter()
            ->all();
    }

    /** One of its options (App\Support\ThemeOptions), or that option's default. */
    public function option(string $key): mixed
    {
        $definition = ThemeOptions::all()[$key] ?? null;
        $value = ($this->options ?? [])[$key] ?? null;

        // Only one of its own choices ever reaches the page (a width, a mode): anything else, its default.
        if ($value === null || ($definition['kind'] ?? null) === 'choice' && ! array_key_exists((string) $value, $definition['options'])) {
            return $definition['default'] ?? null;
        }

        return $value;
    }

    /** A surface's colour in light or dark mode (a hex value), or none. */
    public function surface(string $surface, string $mode): ?string
    {
        $value = ($this->options['surfaces'][$surface][$mode] ?? null);

        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? $value : null;
    }
}
