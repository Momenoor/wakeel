<?php

namespace App\Services\MMS;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The folders the system made in the matters' OneDrive folders, looked for once a day: the standard subfolders,
 * and the folders the emails and periodic reports were filed in. One renamed, moved or removed by hand is no
 * longer where the system keeps its records — the matter's people and the super administrators are told
 * which, once a day a matter, to put it back under its name.
 */
final class OneDriveFolderCheck
{
    /** A matter's missing folders are told once in this many hours. */
    private const TOLD_HOURS = 20;

    public function __construct(private readonly OneDriveClient $client, private readonly MatterOneDriveFolders $folders) {}

    /**
     * Every matter in progress with a folder: its folders looked for. With $maxSeconds it stops starting new
     * matters once the time is up (the rest are checked the next day — the matters are taken oldest-checked first).
     *
     * @return array{checked: int, missing: int, stopped: bool, errors: list<string>}
     */
    public function run(?int $maxSeconds = null): array
    {
        $deadline = $maxSeconds !== null ? microtime(true) + $maxSeconds : null;
        $result = ['checked' => 0, 'missing' => 0, 'stopped' => false, 'errors' => []];

        $rows = MatterOneDriveFolder::query()
            ->where('status', MatterOneDriveFolder::CREATED)
            ->whereNotNull('drive_item_id')
            ->whereNull('moved_at')
            ->whereHas('matter', fn ($query) => $query->whereNull('final_report_at'))
            ->with(['matter', 'party'])
            ->orderBy('checked_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (MatterOneDriveFolder $row): bool => $row->matter !== null && filled($row->party?->onedrive_email));

        foreach ($rows as $row) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $result['stopped'] = true;

                break;
            }

            try {
                $missing = $this->missingIn($row);
                $row->forceFill(['checked_at' => now()])->save();
                $result['checked']++;

                if ($missing !== []) {
                    $result['missing']++;
                    $this->tell($row, $missing);
                }
            } catch (Throwable $e) {
                Log::warning('OneDrive folders not checked for matter '.$row->matter_id.': '.$e->getMessage());
                $result['errors'][] = $row->matter->year.'/'.$row->matter->number.': '.$e->getMessage();
            }
        }

        return $result;
    }

    /**
     * The folders of this matter folder that are not where the system expects them: the matter's own folder, else
     * each standard subfolder and each folder an email or a periodic report was filed in.
     *
     * @return list<string> paths within the matter's folder — the matter's folder's own name when it is gone
     */
    public function missingIn(MatterOneDriveFolder $row): array
    {
        $matter = $row->matter;
        $user = (string) $row->party->onedrive_email;

        try {
            $this->client->itemInfo($user, (string) $row->drive_item_id);
        } catch (OneDriveNotFound $e) {
            if (! $e->isItem()) {
                throw $e;
            }

            return [(string) $row->folder_name];
        }

        $expected = collect([
            ...MatterOneDriveFolders::subfoldersFor($matter),
            ...MatterEmail::query()->where('matter_id', $matter->getKey())->whereNotNull('onedrive->folder')->pluck('onedrive')->flatMap(fn ($onedrive): array => [
                (string) (((array) $onedrive)['folder'] ?? ''),
                (string) (((array) $onedrive)['attachments_folder'] ?? ''),
                ...array_map(fn ($copy): string => (string) (((array) $copy)['folder'] ?? ''), (array) (((array) $onedrive)['copies'] ?? [])),
            ])->all(),
            ...$matter->periodicReports()->pluck('folder')->map(fn ($folder): string => (string) $folder)->all(),
        ])->map(fn (string $path): string => MatterOneDriveFolders::cleanPath($path))->filter()->unique()->values();

        $listings = [];

        return $expected->reject(fn (string $path): bool => $this->folders->walk($user, (string) $row->drive_item_id, $path, $listings) !== null)->values()->all();
    }

    /**
     * The matter's people and the super administrators told which folders are missing — once a day a matter.
     *
     * @param  list<string>  $missing
     */
    private function tell(MatterOneDriveFolder $row, array $missing): void
    {
        $matter = $row->matter;
        $key = 'onedrive-folders-missing-told:'.$row->getKey();

        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, true, now()->addHours(self::TOLD_HOURS));
        $users = MatterPeople::of([(int) $matter->getKey()], superAdmins: true);

        if ($users->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(trans_choice('A folder is missing in the OneDrive folder of matter :matter|:count folders are missing in the OneDrive folder of matter :matter', count($missing), ['count' => count($missing), 'matter' => $matter->year.'/'.$matter->number]))
            ->body(MatterReplyCollector::bullets([
                ...$missing,
                __('Renamed, moved or deleted by hand? Put each back under its name: the system files emails and documents by these names.'),
            ]))
            ->actions([Action::make('open')->label(__('Open the matter'))->url(MatterResource::getUrl('view', ['record' => $matter], panel: 'mms'))->markAsRead()])
            ->sendToDatabase($users);
    }
}
