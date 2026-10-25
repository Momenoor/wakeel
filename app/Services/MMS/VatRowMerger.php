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
        $pairs = $this->plan()['merge'];

        // Each pair in one transaction: its writes are its own.
        foreach ($pairs as ['vat' => $row, 'fee' => $fee]) {
            DB::transaction(function () use ($row, $fee): void {
                // Entered VAT included, as the toggle that made the pair had it.
                DB::table('fees')->where('id', $fee->id)->update(['vat' => $row->amount, 'is_vat_included' => true, 'updated_at' => now()]);
                // Its payments and files with it — before the row goes (its payments would go with it).
                DB::table('allocations')->where('fee_id', $row->id)->update(['fee_id' => $fee->id, 'updated_at' => now()]);
                DB::table('attachments')->where('attachable_type', Fee::class)->where('attachable_id', $row->id)->update(['attachable_id' => $fee->id]);
                DB::table('fees')->where('id', $row->id)->delete();
            });
        }

        if ($pairs->isEmpty()) {
            return 0;
        }

        // Then each fee's paid status, and its matter's, as they now are — every fee with its payments, and every
        // matter with its fees, read at once.
        // Every matter's offset allowance read at once too (Fee::syncStatus() reads one a fee otherwise).
        Fee::withOffsetAllowances(function () use ($pairs): void {
            foreach (Fee::query()->with('allocations')->whereKey($pairs->pluck('fee.id'))->get() as $merged) {
                // Set without saving it as a person would (whose name it keeps).
                $merged->syncStatus();
                DB::table('fees')->where('id', $merged->id)->update(['status' => $merged->status->value]);
            }
        });

        // One line in the activity log for the whole merge, every pair in it — an entry a pair read the user and
        // the entry back each time.
        activity()->withProperties(['merged' => $pairs->map(fn (array $pair): array => [
            'fee' => $pair['fee']->id, 'vat_row' => $pair['vat']->id, 'vat' => (float) $pair['vat']->amount,
        ])->values()->all()])->log('VAT rows merged into their fees');

        $matterIds = $pairs->pluck('fee.matter_id')->unique()->values();
        $fees = Fee::query()->whereIn('matter_id', $matterIds)->get()->groupBy('matter_id');
        Matter::query()->with('allocations')->whereKey($matterIds)->get()
            ->each(fn (Matter $matter) => $matter->updateCollectionStatus($fees->get($matter->id, collect())));

        return $pairs->count();
    }
}
