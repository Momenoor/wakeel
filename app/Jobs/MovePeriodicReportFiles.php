<?php

namespace App\Jobs;

use App\Models\MatterPeriodicReport;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\PeriodicReports;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The files of steps put in a periodic report moved into its folder in the matter's OneDrive — or, taken out of it,
 * back where they were (PeriodicReports) — on the queue: a step has several files, and OneDrive is asked of each.
 * Whoever did it is told how it went, in their notifications; what went wrong is kept on the report.
 */
class MovePeriodicReportFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  list<int>  $stepIds
     * @param  bool  $in  into the report, else out of it
     */
    public function __construct(public int $reportId, public array $stepIds, public bool $in = true, public ?int $userId = null) {}

    public function handle(PeriodicReports $reports): void
    {
        $report = MatterPeriodicReport::query()->with('matter')->find($this->reportId);

        if (! $report?->matter) {
            return;
        }

        try {
            if (MatterOneDriveFolders::folderFor($report->matter) === null) {
                throw new RuntimeException(__('The matter has no OneDrive folder.'));
            }

            $moved = $this->in ? $reports->moveIn($report, $this->stepIds) : $reports->moveOut($report, $this->stepIds);
            $report->update(['moved_at' => now(), 'error' => null]);
            $notification = Notification::make()->success()
                ->title($this->in ? __(':report: files moved into its folder', ['report' => $report->label()]) : __(':report: files moved back', ['report' => $report->label()]))
                ->body(__(':emails emails and :minutes signed minutes moved.', $moved).($this->in ? ' '.__('Folder: :folder', ['folder' => $report->folder]) : ''));
        } catch (Throwable $e) {
            Log::warning('Periodic report files not moved', ['report' => $report->getKey(), 'error' => $e->getMessage()]);
            $report->update(['error' => Str::limit($e->getMessage(), 1000)]);
            $notification = Notification::make()->danger()
                ->title(__(':report: files not moved', ['report' => $report->label()]))
                ->body($e->getMessage());
        }

        if ($this->userId && ($user = User::find($this->userId))) {
            $notification->sendToDatabase($user);
        }
    }
}
