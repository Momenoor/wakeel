<?php

namespace App\Console\Commands;

use App\Models\Matter;
use App\Services\MMS\VatRowMerger;
use Illuminate\Console\Command;

/**
 * The VAT rows of before merged into their fees (VatRowMerger): listed first, merged only with --apply.
 */
class MergeVatRows extends Command
{
    protected $signature = 'fees:merge-vat-rows {--apply : Merge the clear pairs (without it, only list)}';

    protected $description = 'Merge each VAT fee row into the fee it is the VAT of (lists unless --apply)';

    public function handle(VatRowMerger $merger): int
    {
        ['merge' => $merge, 'review' => $review] = $merger->plan();
        $matters = Matter::withTrashed()->whereKey([...$merge->pluck('vat.matter_id'), ...$review->pluck('vat.matter_id')])->get(['id', 'number', 'year'])->keyBy('id');
        $reference = fn (int $id): string => ($matter = $matters->get($id)) ? $matter->number.'/'.$matter->year : '#'.$id;

        $this->info(count($merge).' to merge (each VAT row into its fee):');
        $this->table(['Matter', 'Fee', 'Basic', 'VAT row', 'VAT', 'Total'], $merge->map(fn (array $pair): array => [
            $reference((int) $pair['vat']->matter_id), $pair['fee']->id, number_format((float) $pair['fee']->amount, 2),
            $pair['vat']->id, number_format((float) $pair['vat']->amount, 2), number_format((float) $pair['fee']->amount + (float) $pair['vat']->amount, 2),
        ])->all());

        $this->warn(count($review).' left as they are, for you to decide:');
        $this->table(['Matter', 'VAT row', 'VAT', 'Date', 'Why'], $review->map(fn (array $item): array => [
            $reference((int) $item['vat']->matter_id), $item['vat']->id, number_format((float) $item['vat']->amount, 2), (string) $item['vat']->date, $item['reason'],
        ])->all());

        if (! $this->option('apply')) {
            $this->line('Nothing changed. Run with --apply to merge the '.count($merge).' above.');

            return self::SUCCESS;
        }

        $this->info($merger->apply().' merged.');

        return self::SUCCESS;
    }
}
