<?php

namespace App\Console\Commands;

use App\Services\MMS\FeeVatAndStatusFix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The fees put right as a fee carrying its own VAT has them (FeeVatAndStatusFix): previewed, applied only with
 * --apply; either way each change written in a log. The same as Fee Data Maintenance → "Merge VAT into the fees
 * and fix statuses".
 */
class FixFeesVat extends Command
{
    protected $signature = 'fees:fix-vat {--apply : Make the changes (without it, only a preview and its log)}';

    protected $description = 'Merge the VAT rows into their fees and fix every fee and collection status, with a log of each change';

    public function handle(FeeVatAndStatusFix $fix): int
    {
        $result = $fix->run(apply: (bool) $this->option('apply'));

        $this->line(Storage::disk('local')->get($result['file']));
        $this->info('Log: '.Storage::disk('local')->path($result['file']));

        return self::SUCCESS;
    }
}
