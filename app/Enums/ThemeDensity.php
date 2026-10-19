<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How much room there is between things. Every padding, margin and gap in Filament is a multiple of Tailwind's
 * --spacing variable, so one value makes the whole system tighter or roomier.
 */
enum ThemeDensity: string implements HasLabel
{
    case COMPACT = 'compact';
    case NORMAL = 'normal';
    case COMFORTABLE = 'comfortable';

    public function getLabel(): string
    {
        return match ($this) {
            self::COMPACT => __('Compact'),
            self::NORMAL => __('Normal'),
            self::COMFORTABLE => __('Comfortable'),
        };
    }

    /** --spacing, in rem (Normal: Tailwind's own 0.25rem). */
    public function spacing(): float
    {
        return match ($this) {
            self::COMPACT => 0.2,
            self::NORMAL => 0.25,
            self::COMFORTABLE => 0.3,
        };
    }
}
