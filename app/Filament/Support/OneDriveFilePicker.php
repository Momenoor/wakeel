<?php

namespace App\Filament\Support;

use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\MatterOneDriveFolders;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Files picked from a matter's OneDrive folder — wherever a form takes
 * files: a letter's or minutes' email (EmailSendFields), a matter request.
 * Those at the folder's top and one folder down are offered at once, any
 * other by searching; each picked is fetched only from inside the matter's
 * folder, and only one the user may see.
 */
final class OneDriveFilePicker
{
    /** Between a pick's folder (ours) and its item (OneDrive's). */
    private const SEPARATOR = '|';

    public static function field(?Matter $matter, string $name = 'onedrive_files'): Select
    {
        $folders = fn (): Collection => self::folders($matter);
        $options = fn (string $words = ''): array => $folders()
            ->flatMap(function (MatterOneDriveFolder $folder) use ($words, $folders): array {
                try {
                    $files = app(MatterOneDriveExplorer::class)->files($folder, $words);
                } catch (Throwable) {
                    return [];
                }

                $whose = $folders()->count() > 1 ? $folder->party?->name : null;

                return collect($files)->mapWithKeys(fn (array $file): array => [
                    $folder->getKey().self::SEPARATOR.$file['id'] => implode(' — ', array_filter([$file['name'], $file['where'], $whose])),
                ])->all();
            })
            ->all();

        return Select::make($name)
            ->label(__('From OneDrive'))
            ->multiple()
            ->searchable()
            ->options(fn (): array => $options())
            ->getSearchResultsUsing(fn (string $search): array => $options($search))
            ->getOptionLabelsUsing(fn (array $values): array => collect($values)
                ->mapWithKeys(fn (string $value): array => [$value => self::names($matter, [$value])[0] ?? $value])
                ->all())
            ->visible(fn (): bool => $folders()->isNotEmpty())
            ->live();
    }

    /**
     * The names of the files picked.
     *
     * @param  list<string>  $picked
     * @return list<string>
     */
    public static function names(?Matter $matter, array $picked): array
    {
        $folders = self::folders($matter)->keyBy('id');

        return array_values(array_filter(array_map(function ($value) use ($folders): ?string {
            [$folderId, $itemId] = self::split($value);
            $folder = $folders->get($folderId);

            try {
                return $folder ? app(MatterOneDriveExplorer::class)->name($folder, $itemId) : null;
            } catch (Throwable) {
                return null;
            }
        }, $picked)));
    }

    /**
     * The files picked, fetched now. One that can't be had stops what's
     * being done (Halt) — told why, the form left open.
     *
     * @param  list<string>  $picked
     * @return list<array{name: string, contents: string}>
     */
    public static function fetch(?Matter $matter, array $picked): array
    {
        if ($picked === []) {
            return [];
        }

        $folders = self::folders($matter)->keyBy('id');

        try {
            return array_map(function ($value) use ($folders): array {
                [$folderId, $itemId] = self::split($value);
                $folder = $folders->get($folderId);

                if (! $folder) {
                    throw new RuntimeException(__('That is not in this matter\'s folder.'));
                }

                return app(MatterOneDriveExplorer::class)->fetch($folder, $itemId);
            }, array_values($picked));
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('A file from OneDrive could not be attached'))->body($e->getMessage())->send();

            throw new Halt;
        }
    }

    /**
     * The matter's folders made in OneDrive that this user may see.
     *
     * @return Collection<int, MatterOneDriveFolder>
     */
    private static function folders(?Matter $matter): Collection
    {
        if (! $matter) {
            return collect();
        }

        // Once a request: asked of the field's options, labels and visibility alike.
        return once(fn (): Collection => MatterOneDriveFolders::visibleTo($matter, auth()->user())
            ->filter(fn (MatterOneDriveFolder $f) => $f->isCreated())
            ->values());
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function split(mixed $value): array
    {
        [$folderId, $itemId] = array_pad(explode(self::SEPARATOR, (string) $value, 2), 2, '');

        return [(int) $folderId, $itemId];
    }
}
