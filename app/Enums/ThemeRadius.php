<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How round a theme's corners are. Filament's styles take their corners from Tailwind's --radius-* variables,
 * so a theme only gives those variables new values.
 */
enum ThemeRadius: string implements HasLabel
{
    case NONE = 'none';
    case SMALL = 'sm';
    case MEDIUM = 'md';
    case LARGE = 'lg';
    case FULL = 'xl';

    public function getLabel(): string
    {
        return match ($this) {
            self::NONE => __('Square'),
            self::SMALL => __('Slightly rounded'),
            self::MEDIUM => __('Rounded'),
            self::LARGE => __('Very rounded'),
            self::FULL => __('Soft'),
        };
    }

    /** The scale every --radius-* is multiplied by (Medium: Filament's own). */
    public function scale(): float
    {
        return match ($this) {
            self::NONE => 0,
            self::SMALL => 0.5,
            self::MEDIUM => 1,
            self::LARGE => 1.5,
            self::FULL => 2,
        };
    }
}
