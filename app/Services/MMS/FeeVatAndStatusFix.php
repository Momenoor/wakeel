<?php

namespace App\Services\MMS;

use App\Enums\FeeStatus;
use App\Enums\MatterCollectionStatus;
use App\Models\Allocation;
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

        $plan = $mergeVat ? $this->merger->plan() : ['merge' => collect(), 'review' => collect()];
        // The merged fees' and their matters' statuses as they were before the merge (which sets them): what the
        // log compares with.
        $before = $apply ? DB::table('fees')->whereIn('id', $plan['merge']->pluck('fee.id'))->pluck('status', 'id') : collect();
        $mattersBefore = $apply ? DB::table('matters')->whereIn('id', $plan['merge']->pluck('fee.matter_id')->unique())->pluck('collection_status', 'id') : collect();

        [$merged, $review] = $mergeVat ? $this->mergeVat($apply, $plan, $ref, $log) : [0, 0];
        // A preview: each fee as the merge will leave it.
        $fees = $this->feeStatuses($apply, $ref, $log, $apply ? collect() : $plan['merge'], $before);
        $matters = $this->matterStatuses($apply, $ref, $log, $mattersBefore);

        $log[] = '';
        $log[] = "Summary: {$merged} VAT row(s) merged, {$review} left for review, {$fees} fee status(es) and {$matters} matter collection status(es) ".($apply ? 'changed' : 'to change').'.';

        return ['file' => FeeFixLog::save('fees', $apply, $log), 'merged' => $merged, 'review' => $review, 'fees' => $fees, 'matters' => $matters];
    }

    /**
     * @param  list<string>  $log
     * @return array{0: int, 1: int}
     */
    private function mergeVat(bool $apply, array $plan, \Closure $ref, array &$log): array
    {
        ['merge' => $merge, 'review' => $review] = $plan;
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
     * $pending: the merges a preview has not made, each fee taken as it will be (its VAT on it, the VAT row's
     * payments with its own, the row gone). $before: the merged fees' statuses before an applied merge set them.
     *
     * @param  list<string>  $log
     * @param  Collection<int, array{vat: object, fee: object}>  $pending
     * @param  Collection<int, string>  $before
     */
    private function feeStatuses(bool $apply, \Closure $ref, array &$log, Collection $pending, Collection $before): int
    {
        $changes = collect();
        $merging = $pending->mapWithKeys(fn (array $pair): array => [$pair['fee']->id => $pair['vat']]);
        $rows = $merging->mapWithKeys(fn (object $vat): array => [$vat->id => true]);
        $rowPayments = $merging->isEmpty() ? collect() : Allocation::query()->whereIn('fee_id', $rows->keys())->get()->groupBy('fee_id');

        Fee::withOffsetAllowances(fn () => Fee::query()->with('allocations')->chunkById(500, function (Collection $fees) use ($changes, $merging, $rows, $rowPayments, $before): void {
            foreach ($fees as $fee) {
                // A VAT row the merge will take away.
                if ($rows->has($fee->id)) {
                    continue;
                }

                if ($row = $merging->get($fee->id)) {
                    $fee->vat = $row->amount;
                    $fee->setRelation('allocations', $fee->allocations->concat($rowPayments->get($row->id, collect())));
                }

                $was = isset($before[$fee->id]) ? FeeStatus::from($before[$fee->id]) : $fee->status;
                $stored = $fee->status;
                $fee->syncStatus();

                if ($fee->status !== $was) {
                    $changes->push(['fee' => $fee, 'before' => $was, 'write' => $fee->status !== $stored]);
                }
            }
        }));

        $log[] = '2. Fee statuses changed: '.$changes->count();
        foreach ($changes as ['fee' => $fee, 'before' => $before]) {
            $log[] = sprintf('   Fee #%d (matter %s, %s): %s → %s — total %s, collected %s',
                $fee->id, $ref($fee->matter_id), $fee->type?->getLabel() ?? '—', self::status($before), self::status($fee->status), FeeFixLog::money($fee->total), FeeFixLog::money($fee->allocations->sum('amount')));
        }

        if ($apply) {
            // Those the merge has not set already.
            $changes->filter(fn (array $change): bool => $change['write'])
                ->groupBy(fn (array $change): string => $change['fee']->status->value)
                ->each(fn (Collection $group, string $status) => DB::table('fees')->whereIn('id', $group->pluck('fee.id'))->update(['status' => $status]));
        }

        $log[] = '';

        return $changes->count();
    }

    /**
     * Every matter's collection status from its fees' totals and its payments — read a chunk at a time, the
     * changes written grouped. $before: the merged fees' matters' statuses before an applied merge set them (a
     * merge leaves a matter's totals as they were — a preview reads them as they are).
     *
     * @param  list<string>  $log
     * @param  Collection<int, string>  $before
     */
    private function matterStatuses(bool $apply, \Closure $ref, array &$log, Collection $before): int
    {
        $changes = collect();

        Matter::query()->with('allocations')->chunkById(500, function (Collection $matters) use ($changes, $before): void {
            $fees = Fee::query()->whereIn('matter_id', $matters->modelKeys())->get()->groupBy('matter_id');

            foreach ($matters as $matter) {
                $own = $fees->get($matter->id, collect());
                $after = $matter->collectionStatusFor($own);
                $was = isset($before[$matter->id]) ? MatterCollectionStatus::from($before[$matter->id]) : $matter->collection_status;

                if ($after !== $was) {
                    $changes->push(['matter' => $matter, 'before' => $was, 'after' => $after, 'fees' => $own->sum(fn (Fee $fee) => $fee->total), 'write' => $after !== $matter->collection_status]);
                }
            }
        });

        $log[] = '3. Matter collection statuses changed: '.$changes->count();
        foreach ($changes as ['matter' => $matter, 'before' => $before, 'after' => $after, 'fees' => $total]) {
            $log[] = sprintf('   Matter %s: %s → %s — fees %s, collected %s',
                $ref($matter->id), self::status($before), self::status($after), FeeFixLog::money($total), FeeFixLog::money($matter->allocations->sum('amount')));
        }

        if ($apply) {
            // Those the merge has not set already.
            $changes->filter(fn (array $change): bool => $change['write'])
                ->groupBy(fn (array $change): string => $change['after']->value)
                ->each(fn (Collection $group, string $status) => DB::table('matters')->whereIn('id', $group->pluck('matter.id'))->update(['collection_status' => $status]));
        }

        return $changes->count();
    }

    private static function status(FeeStatus|MatterCollectionStatus|string|null $status): string
    {
        return $status instanceof \BackedEnum ? $status->value : (string) ($status ?? '—');
    }
}
