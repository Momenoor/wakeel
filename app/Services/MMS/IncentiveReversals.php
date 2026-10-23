<?php

namespace App\Services\MMS;

use App\Models\Fee;
use App\Models\IncentiveAssistantLine;
use App\Models\IncentiveLine;
use App\Models\IncentiveReversal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A fee deleted after its incentive was paid.
 *
 * The finalized calculation it was paid in stays as it was paid — its line is kept, no longer pointing at the
 * fee. What each assistant was paid for that fee is recorded (an IncentiveReversal, with the fee as it was) and
 * taken back in the next calculation run: a negative line of the assistant's there, described in full.
 *
 * An assistant has one share per matter, for all of its fees together; the deleted fee's part of it is its part
 * of the matter's net amount (or, with a percentage typed in for the assistant, that percentage of the fee), and
 * the same part of what was added or taken off it (the month's bonus or penalty).
 */
class IncentiveReversals
{
    /**
     * The fee's lines in finalized calculations: its incentive already paid.
     *
     * @return Builder<IncentiveLine>
     */
    public static function paidLines(Fee $fee): Builder
    {
        return IncentiveLine::query()
            ->where('fee_id', $fee->getKey())
            ->where('is_reversal', false)
            ->whereHas('calculation', fn (Builder $query) => $query->where('status', 'finalized'));
    }

    public static function isPaid(Fee $fee): bool
    {
        return self::paidLines($fee)->exists();
    }

    /** Asked before a fee is deleted: whether its incentive was already paid, and what deleting it then does. */
    public static function deleteWarning(?Fee $fee): string
    {
        return $fee && self::isPaid($fee)
            ? __('This fee\'s incentive has already been paid. Deleting it deducts the paid incentive from its assistants in the next incentive calculation.')
            : __('filament-actions::modal.confirmation');
    }

    /** As the fee is deleted: its draft lines go with it; what was paid on it is kept to be taken back. */
    public function record(Fee $fee): void
    {
        IncentiveLine::query()
            ->where('fee_id', $fee->getKey())
            ->whereHas('calculation', fn (Builder $query) => $query->where('status', 'draft'))
            ->delete();

        foreach (self::paidLines($fee)->get() as $line) {
            $matterLines = IncentiveLine::query()
                ->where('incentive_calculation_id', $line->incentive_calculation_id)
                ->where('matter_id', $line->matter_id)
                ->where('is_reversal', false)
                ->get(['id', 'net_amount']);
            $matterNet = (float) $matterLines->sum('net_amount');

            $shares = IncentiveAssistantLine::query()
                ->whereIn('incentive_line_id', $matterLines->modelKeys())
                ->whereNull('incentive_reversal_id')
                ->get();

            foreach ($shares as $share) {
                $amount = $this->feePart($line, $share, $matterNet);

                if ($amount <= 0) {
                    continue;
                }

                IncentiveReversal::create([
                    'matter_id' => $line->matter_id,
                    'party_id' => $share->party_id,
                    'fee_id' => $fee->getKey(),
                    'fee_type' => $fee->type?->value,
                    'fee_amount' => $fee->amount,
                    'fee_date' => $fee->date,
                    'paid_line_id' => $line->getKey(),
                    'paid_calculation_id' => $line->incentive_calculation_id,
                    'paid_amount' => $share->total_amount,
                    'amount' => $amount,
                    'deleted_by' => auth()->id(),
                ]);
            }
        }
    }

    /** The deleted fee's part of what an assistant was paid for the matter. */
    private function feePart(IncentiveLine $line, IncentiveAssistantLine $share, float $matterNet): float
    {
        $shareAmount = (float) $share->share_amount;

        if ($shareAmount <= 0) {
            return 0.0;
        }

        $feeShare = $share->percentage_override !== null
            ? (float) $line->fee_amount_excl_vat * max(0.0, (float) $share->percentage_override - (float) $line->total_deduction_pct) / 100
            : ($matterNet > 0 ? $shareAmount * (float) $line->net_amount / $matterNet : 0.0);

        return round((float) $share->total_amount * min(1.0, $feeShare / $shareAmount), 2);
    }

    /**
     * Taken back in a calculation being run: those not taken back yet (or already in this one, rebuilt), one
     * negative line per assistant under one line per paid fee. A draft deleted gives them back (its id emptied).
     */
    public function apply(Model $calculation): void
    {
        IncentiveLine::query()->where('incentive_calculation_id', $calculation->getKey())->where('is_reversal', true)->delete();
        IncentiveReversal::query()->where('applied_calculation_id', $calculation->getKey())->update(['applied_calculation_id' => null]);

        $pending = IncentiveReversal::query()
            ->whereNull('applied_calculation_id')
            ->whereNotNull('matter_id')
            ->orderBy('id')
            ->get();

        foreach ($pending->groupBy(fn (IncentiveReversal $reversal): string => $reversal->paid_line_id.'-'.$reversal->fee_id) as $group) {
            $line = IncentiveLine::create([
                'incentive_calculation_id' => $calculation->getKey(),
                'matter_id' => $group->first()->matter_id,
                'is_reversal' => true,
                'net_amount' => -1 * (float) $group->sum('amount'),
            ]);

            foreach ($group as $reversal) {
                IncentiveAssistantLine::create([
                    'incentive_line_id' => $line->getKey(),
                    'party_id' => $reversal->party_id,
                    'incentive_reversal_id' => $reversal->getKey(),
                    'share_amount' => -1 * (float) $reversal->amount,
                    'extra_percentage' => 0,
                    'extra_amount' => 0,
                    'minimum_penalty_pct' => 0,
                    'minimum_penalty_amount' => 0,
                    'total_amount' => -1 * (float) $reversal->amount,
                ]);

                $reversal->update(['applied_calculation_id' => $calculation->getKey()]);
            }
        }
    }
}
