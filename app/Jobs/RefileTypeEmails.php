<?php

namespace App\Jobs;

use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\User;
use App\Services\MMS\ExistingEmailsRefiling;
use App\Services\MMS\MatterReplyCollector;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A matter type's email folders or names changed (Matter types → Edit → "Apply to existing matters"): in every
 * matter of the type (and of the types that share its folders) that has a OneDrive folder, its emails moved and
 * renamed to where and as the type says now (ExistingEmailsRefiling). On the queue, matter by matter; whoever asked
 * is told in the bell what was done.
 */
class RefileTypeEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  list<int>  $typeIds  the types whose matters' emails are put in their place
     */
    public function __construct(public array $typeIds, public bool $finishedToo, public ?int $userId) {}

    public function handle(ExistingEmailsRefiling $refiling): void
    {
        $done = ['matters' => 0, 'moved' => 0, 'renamed' => 0, 'failed' => 0];
        $errors = [];

        Matter::query()
            ->whereIn('type_id', $this->typeIds)
            ->when(! $this->finishedToo, fn ($query) => $query->whereNull('final_report_at'))
            ->whereHas('oneDriveFolders', fn ($query) => $query->where('status', MatterOneDriveFolder::CREATED)->whereNotNull('drive_item_id'))
            ->orderBy('id')
            ->chunkById(50, function ($matters) use ($refiling, &$done, &$errors): void {
                foreach ($matters as $matter) {
                    try {
                        $result = $refiling->refile($matter);

                        foreach (['moved', 'renamed', 'failed'] as $what) {
                            $done[$what] += $result[$what];
                        }
                        $done['matters'] += ($result['moved'] + $result['renamed']) > 0 ? 1 : 0;
                    } catch (Throwable $e) {
                        Log::warning('Emails not refiled for matter '.$matter->getKey().': '.$e->getMessage());
                        $errors[] = $matter->year.'/'.$matter->number.': '.$e->getMessage();
                    }
                }
            });

        if ($this->userId && $user = User::find($this->userId)) {
            self::notification($done, $errors)->sendToDatabase($user);
        }
    }

    /**
     * What was done: the matters touched, the emails moved to their new folder and those only renamed.
     *
     * @param  array{matters: int, moved: int, renamed: int, failed: int}  $done
     * @param  list<string>  $errors
     */
    public static function notification(array $done, array $errors): Notification
    {
        $points = array_filter([
            trans_choice(':count email moved to its new folder.|:count emails moved to their new folders.', $done['moved'], ['count' => $done['moved']]),
            $done['renamed'] ? trans_choice(':count email renamed in its folder.|:count emails renamed in their folders.', $done['renamed'], ['count' => $done['renamed']]) : null,
            $done['failed'] ? trans_choice(':count email could not be moved (see the log).|:count emails could not be moved (see the log).', $done['failed'], ['count' => $done['failed']]) : null,
            ...$errors,
        ]);

        return Notification::make()
            ->title(trans_choice('Emails put in their place in no matter.|Emails put in their place in :count matter.|Emails put in their place in :count matters.', $done['matters'], ['count' => $done['matters']]))
            ->body($points !== [] ? MatterReplyCollector::bullets(array_values($points)) : null)
            ->status($errors !== [] || $done['failed'] > 0 ? 'warning' : 'success');
    }
}
