<?php

namespace App\Models;

use App\Enums\ThemeDensity;
use App\Enums\ThemeRadius;
use App\Enums\ThemeSidebar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How the system looks: colours, fonts, corners, spacing and the sidebar. Applied by App\Support\ThemeManager
 * to every page of the panels.
 */
#[Fillable('name', 'colors', 'latin_font_id', 'arabic_font_id', 'radius', 'density', 'sidebar')]
class Theme extends Model
{
    /** The colours a theme can set: Filament's own names. */
    public const COLORS = ['primary', 'gray', 'info', 'success', 'warning', 'danger'];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
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
     * The colours it sets, each a hex value ("#2563eb"); one left empty keeps Filament's.
     *
     * @return array<string, string>
     */
    public function setColors(): array
    {
        return collect($this->colors ?? [])
            ->only(self::COLORS)
            ->filter(fn ($hex): bool => is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1)
            ->all();
    }
}
