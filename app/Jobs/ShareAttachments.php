<?php

namespace App\Jobs;

use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\Letters\SharedAttachments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The files of a letter's or minutes' email put in a folder of the matter's OneDrive folder and shared by link
 * (SharedAttachments::shareNow()), on the queue so the send screen does not wait on Microsoft: its progress —
 * each file copied, then the link made — kept for the screen's progress bar, and the link remembered on the
 * letter or minutes (shared_files) when done. The files were staged in a folder of their own, removed after.
 */
class ShareAttachments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param  Model  $record  the letter or minutes (with a matter and shared_files)
     * @param  list<array{path: string, name: string}>  $files  staged in $directory of the local disk
     * @param  list<string>  $to  whom the email goes to, for the folder
     */
    public function __construct(
        public Model $record,
        public array $files,
        public string $directory,
        public string $folderName,
        public array $to,
    ) {}

    public function handle(SharedAttachments $shared): void
    {
        $record = $this->record;

        try {
            $result = $shared->shareNow(
                $record->matter,
                LetterMailer::attachedFiles($this->files),
                $this->folderName,
                $this->to,
                $record->shared_files,
                fn (string $step, int $done, int $total, string $name) => SharedAttachments::setProgress($record, $step, $done, $total, $name),
            );
            $record->forceFill(['shared_files' => $result])->save();
            SharedAttachments::setProgress($record, SharedAttachments::DONE, count($this->files), count($this->files));
        } catch (Throwable $e) {
            Log::warning('Files not shared by link', ['record' => $record::class.'#'.$record->getKey(), 'error' => $e->getMessage()]);
            SharedAttachments::setProgress($record, SharedAttachments::FAILED, error: $e->getMessage());
        } finally {
            Storage::disk('local')->deleteDirectory($this->directory);
        }
    }
}
