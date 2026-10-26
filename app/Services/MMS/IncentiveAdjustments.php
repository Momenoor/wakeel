<?php

namespace App\Services\MMS;

use App\Enums\IncentiveAdjustmentType;
use App\Models\IncentiveAdjustment;
use App\Models\IncentiveAssistantLine;
use App\Models\IncentiveCalculation;
use App\Models\IncentiveLine;
use App\Support\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixed amounts entered by hand on an assistant's incentive in a draft calculation: an amount added to it, or a
 * deduction taken off it, with its reason.
 *
 * Each is a line of its own in the assistant's calculation — of no matter and no fee — so it is counted wherever
 * their lines are (their total, their statement, My Incentive, payroll), and a deduction beyond what they earned
 * is taken from their salary as their fixed deduction is. Kept as entered and its line built again on every run.
 */
class IncentiveAdjustments
{
    public function add(IncentiveCalculation $calculation, int $partyId, IncentiveAdjustmentType $type, float $amount, string $reason): IncentiveAdjustment
    {
        $this->assertDraft($calculation);

        return DB::transaction(function () use ($calculation, $partyId, $type, $amount, $reason): IncentiveAdjustment {
            $adjustment = IncentiveAdjustment::create([
                'incentive_calculation_id' => $calculation->getKey(),
                'party_id' => $partyId,
                'type' => $type,
                'amount' => round($amount, 2),
                'reason' => $reason,
                'created_by' => auth()->id(),
            ]);

            $this->apply($calculation);

            return $adjustment;
        });
    }

    public function remove(IncentiveAdjustment $adjustment): void
    {
        $this->assertDraft($adjustment->calculation);

        DB::transaction(function () use ($adjustment): void {
            $adjustment->delete();
            $this->apply($adjustment->calculation);
        });
    }

    /** Their lines in a calculation, built again: one per amount entered, its own line under a line of its own. */
    public function apply(Model $calculation): void
    {
        // Lines rebuilt from what was entered (that is logged, as it is added or removed): not logged one by one.
        ActivityLog::quietly(fn () => $this->rebuild($calculation));
    }

    private function rebuild(Model $calculation): void
    {
        IncentiveLine::query()->where('incentive_calculation_id', $calculation->getKey())->where('is_adjustment', true)->delete();

        $adjustments = IncentiveAdjustment::query()->where('incentive_calculation_id', $calculation->getKey())->orderBy('id')->get();

        foreach ($adjustments as $adjustment) {
            $amount = $adjustment->signedAmount();

            $line = IncentiveLine::create([
                'incentive_calculation_id' => $calculation->getKey(),
                'is_adjustment' => true,
                'net_amount' => $amount,
            ]);

            IncentiveAssistantLine::create([
                'incentive_line_id' => $line->getKey(),
                'party_id' => $adjustment->party_id,
                'incentive_adjustment_id' => $adjustment->getKey(),
                'share_amount' => $amount,
                'extra_percentage' => 0,
                'extra_amount' => 0,
                'minimum_penalty_pct' => 0,
                'minimum_penalty_amount' => 0,
                'total_amount' => $amount,
            ]);
        }
    }

    private function assertDraft(?IncentiveCalculation $calculation): void
    {
        if (! $calculation?->isDraft()) {
            throw new RuntimeException(__('Only a draft calculation can be changed.'));
        }
    }
}
