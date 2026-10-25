<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * VAT — its rate (Settings → Financial Settings → VAT) and the one way an amount is split by it. Every fee is
 * split here (App\Filament\Support\FeeVatFields), and anything else that takes VAT out of an amount reads the
 * rate here; no other place works it out.
 */
final class Vat
{
    /** The setting: the rate, in percent. */
    public const SETTING = 'fees_vat_rate';

    public const DEFAULT_RATE = 5.0;

    /** The rate in percent (5 for 5%). */
    public static function rate(): float
    {
        try {
            return (float) Setting::get(self::SETTING, self::DEFAULT_RATE);
        } catch (Throwable) {
            return self::DEFAULT_RATE;
        }
    }

    /** The rate as a fraction (0.05 for 5%). */
    public static function fraction(): float
    {
        return self::rate() / 100;
    }

    /**
     * An amount as entered, split: included, it is the total — the basic is the total less its VAT; not included,
     * it is the basic — the VAT is added to it. The VAT is always total − basic, so basic + VAT is the total to the
     * fils, however it rounds.
     *
     * @return array{basic: float, vat: float, total: float}
     */
    public static function split(float $entered, bool $included, ?float $rate = null): array
    {
        $fraction = ($rate ?? self::rate()) / 100;

        if ($included) {
            $total = round($entered, 2);
            $basic = round($total / (1 + $fraction), 2);

            return ['basic' => $basic, 'vat' => round($total - $basic, 2), 'total' => $total];
        }

        $basic = round($entered, 2);
        $vat = round($basic * $fraction, 2);

        return ['basic' => $basic, 'vat' => $vat, 'total' => round($basic + $vat, 2)];
    }
}
