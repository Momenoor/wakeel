<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/** A fixed amount entered by hand on an assistant's incentive (IncentiveAdjustment): added to it or taken off it. */
enum IncentiveAdjustmentType: string implements HasColor, HasIcon, HasLabel
{
    case ADDITION = 'addition';
    case DEDUCTION = 'deduction';

    public function getLabel(): string
    {
        return match ($this) {
            self::ADDITION => __('Additional amount'),
            self::DEDUCTION => __('Deduction'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ADDITION => 'success',
            self::DEDUCTION => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::ADDITION => Heroicon::OutlinedPlusCircle,
            self::DEDUCTION => Heroicon::OutlinedMinusCircle,
        };
    }

    /** Its amount as it counts in the incentive: taken off, negative. */
    public function signed(float $amount): float
    {
        return $this === self::DEDUCTION ? -1 * $amount : $amount;
    }
}
