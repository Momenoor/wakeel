<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** The sidebar: as Filament draws it, or filled with the theme's primary colour. */
enum ThemeSidebar: string implements HasLabel
{
    case PLAIN = 'plain';
    case COLORED = 'colored';

    public function getLabel(): string
    {
        return match ($this) {
            self::PLAIN => __('Plain'),
            self::COLORED => __('In the primary colour'),
        };
    }
}
