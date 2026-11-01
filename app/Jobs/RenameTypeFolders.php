<?php

namespace App\Jobs;

use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterReplyCollector;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A matter type's folder structure changed and some of its folders were renamed (Matter types → Edit → "Rename
 * folders in existing matters"): in every matter of the type (and of the types that share its folders) that has
 * a OneDrive folder, each old folder renamed to its new name — what is in it with it — and every path the system
 * keeps under it changed (MatterOneDriveFolders::renameWithin()). On the queue: a matter's folders take seconds
 * each; whoever asked is told what was done in the bell.
 */
class RenameTypeFolders implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  list<int>  $typeIds  the types whose matters are renamed in
     * @param  array<string, string>  $renames  old folder (as the structure wrote it) => new
     */
    public function __construct(public array $typeIds, public array $renames, public bool $finishedToo, public ?int $userId) {}

    public function handle(MatterOneDriveFolders $folders): void
    {
        $done = ['matters' => 0, 'folders' => 0, 'emails' => 0, 'files' => 0, 'reports' => 0, 'links' => 0];
        $errors = [];

        Matter::query()
            ->whereIn('type_id', $this->typeIds)
            ->when(! $this->finishedToo, fn ($query) => $query->whereNull('final_report_at'))
            ->whereHas('oneDriveFolders', fn ($query) => $query->where('status', MatterOneDriveFolder::CREATED)->whereNotNull('drive_item_id'))
            ->orderBy('id')
            ->chunkById(50, function ($matters) use ($folders, &$done, &$errors): void {
                foreach ($matters as $matter) {
                    try {
                        $renamed = false;

                        foreach ($this->renames as $from => $to) {
                            // Each as this matter writes it: its placeholders (its phase …) filled in.
                            $result = $folders->renameWithin($matter, MatterOneDriveFolders::resolvePath($from, $matter, null, ''), MatterOneDriveFolders::resolvePath($to, $matter, null, ''));

                            foreach ($result as $what => $count) {
                                $done[$what] += $count;
                            }
                            $renamed = $renamed || array_sum($result) > 0;
                        }

                        $done['matters'] += $renamed ? 1 : 0;
                    } catch (Throwable $e) {
                        Log::warning('Folders not renamed for matter '.$matter->getKey().': '.$e->getMessage());
                        $errors[] = $matter->year.'/'.$matter->number.': '.$e->getMessage();
                    }
                }
            });

        if ($this->userId && $user = User::find($this->userId)) {
            self::notification($done, $errors)->sendToDatabase($user);
        }
    }

    /**
     * What was done: the matters touched, the folders renamed in OneDrive, and the records changed with them.
     *
     * @param  array{matters: int, folders: int, emails: int, files: int, reports: int, links: int}  $done
     * @param  list<string>  $errors
     */
    public static function notification(array $done, array $errors): Notification
    {
        $points = array_filter([
            trans_choice(':count folder renamed in OneDrive.|:count folders renamed in OneDrive.', $done['folders'], ['count' => $done['folders']]),
            $done['emails'] ? trans_choice(':count email\'s folder updated.|:count emails\' folders updated.', $done['emails'], ['count' => $done['emails']]) : null,
            $done['files'] ? trans_choice(':count file\'s path updated.|:count files\' paths updated.', $done['files'], ['count' => $done['files']]) : null,
            $done['reports'] ? trans_choice(':count periodic report\'s folder updated.|:count periodic reports\' folders updated.', $done['reports'], ['count' => $done['reports']]) : null,
            $done['links'] ? trans_choice(':count link updated.|:count links updated.', $done['links'], ['count' => $done['links']]) : null,
            ...$errors,
        ]);

        return Notification::make()
            ->title(trans_choice('Folders renamed in no matter.|Folders renamed in :count matter.|Folders renamed in :count matters.', $done['matters'], ['count' => $done['matters']]))
            ->body($points !== [] ? MatterReplyCollector::bullets(array_values($points)) : null)
            ->status($errors !== [] ? 'warning' : 'success');
    }
}
