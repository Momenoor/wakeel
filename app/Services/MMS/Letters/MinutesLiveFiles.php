<?php

namespace App\Services\MMS\Letters;

use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Models\User;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * The files in a matter's OneDrive folders, for the live view of its minutes: listed for whoever may view the
 * matter (the folders they may see, made already) to show one over the minutes on their own screen — and the
 * one the office chose on Record the meeting (matter_minutes.live_file: its folder, item and name), shown over
 * the minutes on every live view at once until it is hidden — picked in the matter's folder as a file manager
 * (MatterOneDriveFiles).
 */
class MinutesLiveFiles
{
    public function __construct(private readonly MatterOneDriveExplorer $explorer) {}

    /**
     * The matter's folders this user may see, made already.
     *
     * @return Collection<int, MatterOneDriveFolder>
     */
    public static function folders(Matter $matter, ?User $user): Collection
    {
        return MatterOneDriveFolders::visibleTo($matter, $user)->filter(fn (MatterOneDriveFolder $folder): bool => $folder->isCreated())->values();
    }

    /**
     * The files at the top of each folder and in the folders directly in it — or, with words, those found
     * anywhere in it. Each with its folder, the assistant whose folder it is, where in it it sits, and the
     * address the live view shows it by; with why a folder could not be read, if one could not.
     *
     * @return array{files: list<array{folder: int, id: string, name: string, where: string, assistant: string, url: string}>, errors: list<string>}
     */
    public function list(MatterMinutes $minutes, ?User $user, string $words = ''): array
    {
        $files = [];
        $errors = [];

        foreach (self::folders($minutes->matter, $user) as $folder) {
            try {
                foreach ($this->explorer->files($folder, $words) as $file) {
                    $files[] = [
                        'folder' => (int) $folder->getKey(),
                        'id' => $file['id'],
                        'name' => $file['name'],
                        'where' => $file['where'],
                        'assistant' => (string) $folder->party?->name,
                        'url' => route('minutes.live.file', [$minutes, $folder, $file['id']]),
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = $folder->party?->name.': '.$e->getMessage();
            }
        }

        return ['files' => $files, 'errors' => $errors];
    }

    /**
     * Show this file ("folder|item": the folder's id and OneDrive's item id) over the minutes on every live
     * view — one in the matter's folder, in a folder the one choosing may see.
     */
    public function show(MatterMinutes $minutes, ?User $user, string $key): void
    {
        [$folderId, $itemId] = array_pad(explode('|', $key, 2), 2, '');
        $folder = self::folders($minutes->matter, $user)->firstWhere('id', (int) $folderId);

        if ($folder === null || $itemId === '') {
            throw new RuntimeException(__('That is not in this matter\'s folder.'));
        }

        $file = $this->explorer->show($folder, $itemId, preview: false);

        $minutes->update(['live_file' => ['folder' => (int) $folder->getKey(), 'item' => $itemId, 'name' => $file['name']]]);
    }

    /** The minutes alone again, on every live view. */
    public static function hide(MatterMinutes $minutes): void
    {
        $minutes->update(['live_file' => null]);
    }

    /**
     * The file shown over the minutes, as the live view takes it — none when none is.
     *
     * @return array{name: string, url: string}|null
     */
    public static function shown(MatterMinutes $minutes): ?array
    {
        $file = $minutes->live_file;

        if (! is_array($file) || blank($file['folder'] ?? null) || blank($file['item'] ?? null)) {
            return null;
        }

        return [
            'name' => (string) ($file['name'] ?? ''),
            'url' => route('minutes.live.file', [$minutes, (int) $file['folder'], (string) $file['item']]),
        ];
    }
}
