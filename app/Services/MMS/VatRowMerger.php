<?php

namespace App\Services\MMS;

use App\Enums\FeeType;
use App\Models\Fee;
use App\Models\Matter;
use App\Support\Vat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The VAT rows of before (fees of type VAT, made beside a fee entered "Including VAT") merged into the fee they
 * belong to, now that a fee carries its own VAT: the row's amount becomes the fee's VAT (entered VAT included,
 * as it was), its payments and files go with it, and the row goes. Only a clear pair — one fee on its matter, of
 * no VAT yet, whose VAT at the rate is the row's amount to the fils; any other is listed for a person to decide,
 * never touched. The fee's basic amount — and so every incentive — is not changed.
 *
 * Run by hand: php artisan fees:merge-vat-rows lists, --apply merges.
 */
class VatRowMerger
{
    /** How many fils a VAT row may be from the fee's VAT at the rate and still be its VAT (rounding). */
    private const FILS = 1;

    /**
     * Each VAT row: the fee it merges into, or why it is left for a person.
     *
     * @return array{merge: Collection<int, array{vat: object, fee: object}>, review: Collection<int, array{vat: object, reason: string}>}
     */
    public function plan(): array
    {
        $rows = DB::table('fees')->where('type', FeeType::VAT->value)->orderBy('id')->get(['id', 'matter_id', 'amount', 'date']);

        // The fees a VAT row may belong to: on its matter, VAT charged on their type, no VAT of their own yet.
        $fees = DB::table('fees')
            ->whereIn('matter_id', $rows->pluck('matter_id')->unique())
            ->where(fn ($query) => $query->whereNull('type')->orWhereNotIn('type', [...FeeType::deductionTypeValues(), FeeType::VAT->value]))
            ->where('vat', 0)
            ->orderBy('id')
            ->get(['id', 'matter_id', 'amount', 'type'])
            ->groupBy('matter_id');

        $rate = Vat::fraction();
        $claimed = [];
        $merge = collect();
        $review = collect();

        foreach ($rows as $row) {
            $onMatter = $fees->get($row->matter_id, collect());
            // In whole fils: a total split VAT included can leave the VAT one fils from the basic's at the rate.
            $matching = $onMatter->filter(fn ($fee): bool => ! isset($claimed[$fee->id])
                && abs((int) round((float) $row->amount * 100) - (int) round((float) $fee->amount * $rate * 100)) <= self::FILS);

            if ($matching->count() === 1) {
                $fee = $matching->first();
                $claimed[$fee->id] = true;
                $merge->push(['vat' => $row, 'fee' => $fee]);

                continue;
            }

            $review->push(['vat' => $row, 'reason' => match (true) {
                $onMatter->isEmpty() => __('No fee on its matter that VAT could belong to.'),
                $matching->isEmpty() => __('Not :rate% of any fee on its matter (:amounts).', [
                    'rate' => Vat::rate(),
                    'amounts' => $onMatter->map(fn ($fee) => number_format((float) $fee->amount, 2))->implode(', '),
                ]),
                default => __('More than one fee on its matter it could be the VAT of.'),
            }]);
        }

        return ['merge' => $merge, 'review' => $review];
    }

    /**
     * The clear pairs merged — each in one transaction. How many.
     */
    public function apply(): int
    {
        $merged = 0;

        foreach ($this->plan()['merge'] as ['vat' => $row, 'fee' => $fee]) {
            DB::transaction(function () use ($row, $fee): void {
                // Entered VAT included, as the toggle that made the pair had it.
                DB::table('fees')->where('id', $fee->id)->update(['vat' => $row->amount, 'is_vat_included' => true, 'updated_at' => now()]);
                // Its payments and files with it — before the row goes (its payments would go with it).
                DB::table('allocations')->where('fee_id', $row->id)->update(['fee_id' => $fee->id, 'updated_at' => now()]);
                DB::table('attachments')->where('attachable_type', Fee::class)->where('attachable_id', $row->id)->update(['attachable_id' => $fee->id]);
                DB::table('fees')->where('id', $row->id)->delete();

                // Paid as before, now on the one fee — set without saving it as a person would (whose name it keeps).
                $merged = Fee::query()->with('allocations')->findOrFail($fee->id);
                $merged->syncStatus();
                DB::table('fees')->where('id', $merged->id)->update(['status' => $merged->status->value]);

                activity()->performedOn($merged)->withProperties(['vat_row' => $row->id, 'vat' => (float) $row->amount])
                    ->log('VAT row merged into the fee');
            });

            Matter::find($fee->matter_id)?->updateCollectionStatus();
            $merged++;
        }

        return $merged;
    }
}
