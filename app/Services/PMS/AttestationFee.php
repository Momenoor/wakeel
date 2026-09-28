<?php

namespace App\Services\PMS;

use App\Enums\PMS\Emirate;
use App\Enums\PMS\PropertyClassification;
use App\Models\Setting;
use App\Models\Unit;

/**
 * The estimated government attestation fee for a contract, from the PMS
 * settings: Sharjah charges a percentage of the rent — one rate for
 * residential units, another for commercial (and industrial / mixed-use) —
 * while Dubai (Ejari) is a fixed fee per contract. Other emirates use the
 * general fixed estimate. Only an estimate; the real fee is confirmed at
 * contract stage.
 */
final class AttestationFee
{
    /**
     * @param  iterable<array{unit: Unit, rent: float}>  $lines
     */
    public static function estimate(iterable $lines): float
    {
        $fee = 0.0;
        $fixedFor = [];

        foreach ($lines as $line) {
            $unit = $line['unit'];
            $emirate = $unit->property?->emirate;

            if ($emirate === Emirate::SHARJAH) {
                $percent = $unit->property_classification === PropertyClassification::RESIDENTIAL
                    ? (float) Setting::get('pms_attestation_fee_sharjah_residential_percent', 0)
                    : (float) Setting::get('pms_attestation_fee_sharjah_commercial_percent', 0);

                $fee += (float) $line['rent'] * $percent / 100;

                continue;
            }

            // A fixed fee is charged once per contract, not per unit.
            $fixedFor[$emirate === Emirate::DUBAI ? 'dubai' : 'other'] = true;
        }

        if (isset($fixedFor['dubai'])) {
            $fee += (float) Setting::get('pms_attestation_fee_dubai', Setting::get('pms_attestation_fee_estimate', 0));
        }

        if (isset($fixedFor['other'])) {
            $fee += (float) Setting::get('pms_attestation_fee_estimate', 0);
        }

        return round($fee, 2);
    }
}
