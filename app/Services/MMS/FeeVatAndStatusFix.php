<?php

namespace App\Services\MMS;

use App\Enums\FeeStatus;
use App\Enums\MatterCollectionStatus;
use App\Models\Fee;
use App\Models\Matter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The fees put right as a fee carrying its own VAT has them: the VAT rows of before merged into their fees
 * (VatRowMerger), then every fee's status and every matter's collection status as they now are — what is
 * collected measured against each fee's total, VAT included. Each change written in a log, line by line; nothing
 * changed but the log for a preview. Statuses are written as they are worked out: grouped, and never through a
 * fee's own save — which would make whoever runs it the one who entered each fee.
 */
class FeeVatAndStatusFix
{
    public function __construct(private readonly VatRowMerger $merger) {}

    /**
     * @return array{file: string, merged: int, review: int, fees: int, matters: int}
     */
    public function run(bool $apply, bool $mergeVat = true): array
    {
        $ref = FeeFixLog::references();
        $log = FeeFixLog::header($mergeVat ? 'VAT merged into the fees, and fee and collection statuses' : 'fee and collection statuses', $apply);

        [$merged, $review] = $mergeVat ? $this->mergeVat($apply, $ref, $log) : [0, 0];
        $fees = $this->feeStatuses($apply, $ref, $log);
        $matters = $this->matterStatuses($apply, $ref, $log);

        $log[] = '';
        $log[] = "Summary: {$merged} VAT row(s) merged, {$review} left for review, {$fees} fee status(es) and {$matters} matter collection status(es) ".($apply ? 'changed' : 'to change').'.';

        return ['file' => FeeFixLog::save('fees', $apply, $log), 'merged' => $merged, 'review' => $review, 'fees' => $fees, 'matters' => $matters];
    }

    /**
     * @param  list<string>  $log
     * @return array{0: int, 1: int}
     */
    private function mergeVat(bool $apply, \Closure $ref, array &$log): array
    {
        ['merge' => $merge, 'review' => $review] = $this->merger->plan();
        $payments = DB::table('allocations')->whereIn('fee_id', $merge->pluck('vat.id'))->selectRaw('fee_id, COUNT(*) as n')->groupBy('fee_id')->pluck('n', 'fee_id');

        $log[] = '1. VAT rows merged into their fees: '.$merge->count();
        foreach ($merge as ['vat' => $vat, 'fee' => $fee]) {
            $log[] = sprintf('   Matter %s — fee #%d: basic %s + VAT %s = %s (VAT row #%d removed, %d payment(s) moved to the fee)',
                $ref((int) $fee->matter_id), $fee->id, FeeFixLog::money($fee->amount), FeeFixLog::money($vat->amount), FeeFixLog::money((float) $fee->amount + (float) $vat->amount), $vat->id, (int) ($payments[$vat->id] ?? 0));
        }

        $log[] = '   Left as they are, for a person to decide: '.$review->count();
        foreach ($review as ['vat' => $vat, 'reason' => $reason]) {
            $log[] = sprintf('   Matter %s — VAT row #%d (%s): %s', $ref((int) $vat->matter_id), $vat->id, FeeFixLog::money($vat->amount), $reason);
        }

        if ($apply) {
            $this->merger->apply();
        }

        $log[] = '';

        return [$merge->count(), $review->count()];
    }

    /**
     * Every fee's status from its payments against its total — all read in chunks, the changes written grouped.
     *
     * @param  list<string>  $log
     */
    private function feeStatuses(bool $apply, \Closure $ref, array &$log): int
    {
        $changes = collect();

        Fee::withOffsetAllowances(fn () => Fee::query()->with('allocations')->chunkById(500, function (Collection $fees) use ($changes): void {
            foreach ($fees as $fee) {
                $before = $fee->status;
                $fee->syncStatus();

                if ($fee->status !== $before) {
                    $changes->push(['fee' => $fee, 'before' => $before]);
                }
            }
        }));

        $log[] = '2. Fee statuses changed: '.$changes->count();
        foreach ($changes as ['fee' => $fee, 'before' => $before]) {
            $log[] = sprintf('   Fee #%d (matter %s, %s): %s → %s — total %s, collected %s',
                $fee->id, $ref($fee->matter_id), $fee->type?->getLabel() ?? '—', self::status($before), self::status($fee->status), FeeFixLog::money($fee->total), FeeFixLog::money($fee->allocations->sum('amount')));
        }

        if ($apply) {
            $changes->groupBy(fn (array $change): string => $change['fee']->status->value)
                ->each(fn (Collection $group, string $status) => DB::table('fees')->whereIn('id', $group->pluck('fee.id'))->update(['status' => $status]));
        }

        $log[] = '';

        return $changes->count();
    }

    /**
     * Every matter's collection status from its fees' totals and its payments — read a chunk at a time, the
     * changes written grouped.
     *
     * @param  list<string>  $log
     */
    private function matterStatuses(bool $apply, \Closure $ref, array &$log): int
    {
        $changes = collect();

        Matter::query()->with('allocations')->chunkById(500, function (Collection $matters) use ($changes): void {
            $fees = Fee::query()->whereIn('matter_id', $matters->modelKeys())->get()->groupBy('matter_id');

            foreach ($matters as $matter) {
                $own = $fees->get($matter->id, collect());
                $after = $matter->collectionStatusFor($own);

                if ($after !== $matter->collection_status) {
                    $changes->push(['matter' => $matter, 'before' => $matter->collection_status, 'after' => $after, 'fees' => $own->sum(fn (Fee $fee) => $fee->total)]);
                }
            }
        });

        $log[] = '3. Matter collection statuses changed: '.$changes->count();
        foreach ($changes as ['matter' => $matter, 'before' => $before, 'after' => $after, 'fees' => $total]) {
            $log[] = sprintf('   Matter %s: %s → %s — fees %s, collected %s',
                $ref($matter->id), self::status($before), self::status($after), FeeFixLog::money($total), FeeFixLog::money($matter->allocations->sum('amount')));
        }

        if ($apply) {
            $changes->groupBy(fn (array $change): string => $change['after']->value)
                ->each(fn (Collection $group, string $status) => DB::table('matters')->whereIn('id', $group->pluck('matter.id'))->update(['collection_status' => $status]));
        }

        return $changes->count();
    }

    private static function status(FeeStatus|MatterCollectionStatus|string|null $status): string
    {
        return $status instanceof \BackedEnum ? $status->value : (string) ($status ?? '—');
    }
}
