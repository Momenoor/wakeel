<?php

namespace App\Jobs;

use App\Models\Matter;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A matter's phase changed by editing it: the new phase's folders made in its OneDrive folders, on the queue so
 * saving the matter never waits on Microsoft.
 */
class MakeMatterPhaseFolders implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $matterId) {}

    public function handle(MatterOneDriveFolders $folders): void
    {
        $matter = Matter::find($this->matterId);

        if ($matter === null || ! MatterOneDriveFolders::enabled()) {
            return;
        }

        try {
            $folders->makePhaseFolders($matter);
        } catch (Throwable $exception) {
            Log::warning('Phase folders not made: '.$exception->getMessage(), ['matter' => $this->matterId]);

            throw $exception;
        }
    }
}
