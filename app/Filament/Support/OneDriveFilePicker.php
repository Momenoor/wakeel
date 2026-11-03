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
                    MatterOneDriveFolders::fileKey($folder, $file['id']) => self::label([$file['name'], $file['where'], $whose]),
                ])->all();
            })
            ->all();

        return Select::make($name)
            ->label(__('From OneDrive'))
            ->multiple()
            ->searchable()
            // The labels carry their parts' directions (label()).
            ->allowHtml()
            ->options(fn (): array => $options())
            ->getSearchResultsUsing(fn (string $search): array => $options($search))
            ->getOptionLabelsUsing(fn (array $values): array => collect($values)
                ->mapWithKeys(fn (string $value): array => [$value => self::label([self::names($matter, [$value])[0] ?? $value])])
                ->all())
            ->visible(fn (): bool => $folders()->isNotEmpty())
            ->live();
    }

    /**
     * A file's name, where it is and whose folder, as one option — each part read in its own direction, so
     * an Arabic folder name beside a Latin file name (or the other way) is not turned about.
     *
     * @param  list<?string>  $parts
     */
    public static function label(array $parts): string
    {
        return implode(' — ', array_map(fn (string $part): string => '<bdi>'.e($part).'</bdi>', array_values(array_filter($parts, fn (?string $part): bool => filled($part)))));
    }

    /**
     * The names of the files picked.
     *
     * @param  list<string>  $picked
     * @return list<string>
     */
    public static function names(?Matter $matter, array $picked): array
    {
        return array_column(self::details($matter, $picked), 'name');
    }

    /**
     * The names and sizes of the files picked — one that cannot be read left out.
     *
     * @param  list<string>  $picked
     * @return list<array{name: string, size: int}>
     */
    public static function details(?Matter $matter, array $picked): array
    {
        $folders = self::folders($matter)->keyBy('id');

        return array_values(array_filter(array_map(function ($value) use ($folders): ?array {
            [$folderId, $itemId] = MatterOneDriveFolders::splitFileKey($value);
            $folder = $folders->get($folderId);

            try {
                return $folder ? app(MatterOneDriveExplorer::class)->details($folder, $itemId) : null;
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
                [$folderId, $itemId] = MatterOneDriveFolders::splitFileKey($value);
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
        return once(fn (): Collection => MatterOneDriveFolders::readyFor($matter, auth()->user()));
    }
}
