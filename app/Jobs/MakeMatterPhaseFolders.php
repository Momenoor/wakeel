<?php

namespace App\Jobs;

use App\Models\Matter;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A matter's phase changed by editing it: the folders named after the old phase renamed to the new (what is in
 * them with them, and the system's records of what is in them), then the new phase's folders made in its OneDrive
 * folders — on the queue, so saving the matter never waits on Microsoft.
 */
class MakeMatterPhaseFolders implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param  ?string  $fromPhase  the phase it was in before, when it changed phase */
    public function __construct(public int $matterId, public ?string $fromPhase = null) {}

    public function handle(MatterOneDriveFolders $folders): void
    {
        $matter = Matter::find($this->matterId);

        if ($matter === null || ! MatterOneDriveFolders::enabled()) {
            return;
        }

        try {
            if (filled($this->fromPhase) && $this->fromPhase !== (string) $matter->phase) {
                $folders->renamePhaseFolders($matter, (string) $this->fromPhase);
            }

            $folders->makePhaseFolders($matter);
        } catch (Throwable $exception) {
            Log::warning('Phase folders not made: '.$exception->getMessage(), ['matter' => $this->matterId]);

            throw $exception;
        }
    }
}
