<?php

namespace App\Services\MMS;

use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\OneDriveFolderReview;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Bringing the active matters' folders in the assistants' OneDrive in line
 * with the standard — one decision at a time (Settings → OneDrive folder
 * review):
 *
 *  - scan(): each assistant's matters folder is listed once, and a folder
 *    counts as a matter's only when its name has both the matter's number
 *    and its year ("DSI Case 3052_2021", "3052-21 Drake Scull", "Case
 *    3052/2021"). Nothing is changed.
 *  - plan(): what applying would do — rename the folder to the standard
 *    name, rename or make the standard subfolders — or make the folder.
 *  - apply(): does it and links the folder to the matter.
 *
 * Nothing is ever deleted, moved or overwritten: folders are only renamed
 * or made, and every file stays where it is.
 */
class OneDriveFolderReviewer
{
    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    public function __construct(
        private readonly OneDriveClient $client,
        private readonly MatterOneDriveFolders $folders,
    ) {}

    /**
     * Whether a folder's name is this matter's: the number as a number of
     * its own (3052, not 13052) and the year — in full anywhere, or as two
     * digits right after or before the number ("3052-21", "21_3052").
     */
    public static function matches(string $name, int|string $number, int|string $year): bool
    {
        $name = strtr($name, self::DIGITS);
        $number = ltrim((string) $number, '0');
        $year = (string) $year;

        if ($number === '' || ! preg_match_all('/\d+/u', $name, $found, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $tokens = $found[0];

        foreach ($tokens as $i => [$digits, $at]) {
            if (ltrim($digits, '0') !== $number) {
                continue;
            }

            foreach ($tokens as $j => [$other, $otherAt]) {
                if ($i === $j) {
                    continue;
                }

                if ($other === $year) {
                    return true;
                }

                // Two digits for the year only right beside the number.
                if (strlen($year) === 4 && $other === substr($year, -2)) {
                    $between = $j > $i
                        ? substr($name, $at + strlen($digits), $otherAt - $at - strlen($digits))
                        : substr($name, $otherAt + strlen($other), $at - $otherAt - strlen($other));

                    if (preg_match('/^[\s\-_\/.]{1,3}$/u', $between) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Matters still being worked on: no final report yet, not deleted.
     *
     * @return Builder<Matter>
     */
    public static function activeMatters(): Builder
    {
        return Matter::query()->whereNull('final_report_at')->withoutTrashed();
    }

    /**
     * Looks through each assistant's OneDrive (or just this one's) and
     * records, for each of their active matters, what is there. Decided
     * rows stay as decided; the others are found afresh.
     *
     * @return array{assistants: int, rows: int, errors: list<string>}
     */
    public function scan(?Party $only = null): array
    {
        $summary = ['assistants' => 0, 'rows' => 0, 'errors' => []];

        foreach ($only ? collect([$only]) : MatterOneDriveFolders::assistantsWithOneDrive() as $assistant) {
            $matters = $this->mattersOf($assistant);

            if ($matters->isEmpty()) {
                continue;
            }

            $summary['assistants']++;

            try {
                $folders = $this->foldersOf($assistant);
            } catch (\Throwable $e) {
                $summary['errors'][] = $assistant->name.': '.$e->getMessage();

                continue;
            }

            $linked = MatterOneDriveFolder::query()
                ->where('party_id', $assistant->getKey())
                ->whereIn('matter_id', $matters->modelKeys())
                ->whereNotNull('drive_item_id')
                ->pluck('drive_item_id', 'matter_id');

            $reviews = OneDriveFolderReview::query()
                ->where('party_id', $assistant->getKey())
                ->whereIn('matter_id', $matters->modelKeys())
                ->get()
                ->keyBy('matter_id');

            foreach ($matters as $matter) {
                $this->record($matter, $assistant, $folders, $linked[$matter->getKey()] ?? null, $reviews->get($matter->getKey()));
                $summary['rows']++;
            }
        }

        return $summary;
    }

    /**
     * The same look for one matter and one assistant — before a new
     * matter's folder is made, so one already there isn't duplicated.
     */
    public function check(Matter $matter, Party $assistant): OneDriveFolderReview
    {
        $linked = MatterOneDriveFolder::query()
            ->where('matter_id', $matter->getKey())
            ->where('party_id', $assistant->getKey())
            ->value('drive_item_id');

        return $this->record($matter, $assistant, $this->foldersOf($assistant), $linked);
    }

    /**
     * The folders in the assistant's matters folder (the path on their
     * profile; their OneDrive root without one). None when the path isn't
     * there yet.
     *
     * @return list<array{id: string, name: string, webUrl: string}>
     */
    private function foldersOf(Party $assistant): array
    {
        $base = $this->client->findFolder($assistant->onedrive_email, (string) $assistant->onedrive_path);

        return $base === null && filled($assistant->onedrive_path) ? [] : $this->client->childFolders($assistant->onedrive_email, $base['id'] ?? null);
    }

    /**
     * What is there for this matter, kept as its review row. A row already
     * decided keeps its decision.
     *
     * @param  list<array{id: string, name: string, webUrl: string}>  $folders
     */
    private function record(Matter $matter, Party $assistant, array $folders, ?string $linkedId, ?OneDriveFolderReview $review = null): OneDriveFolderReview
    {
        $standard = MatterOneDriveFolders::folderName($matter);
        $candidates = array_values(array_filter($folders, fn (array $f): bool => $f['id'] === $linkedId
            || self::matches($f['name'], $matter->number, $matter->year)));

        $status = match (true) {
            count($candidates) === 1 && $candidates[0]['name'] === $standard && $candidates[0]['id'] === $linkedId => OneDriveFolderReview::STANDARD,
            count($candidates) === 1 => OneDriveFolderReview::FOUND,
            count($candidates) > 1 => OneDriveFolderReview::MULTIPLE,
            default => OneDriveFolderReview::MISSING,
        };

        $review ??= OneDriveFolderReview::firstOrNew(['matter_id' => $matter->getKey(), 'party_id' => $assistant->getKey()]);

        // What was decided stays decided.
        if (in_array($review->status, [OneDriveFolderReview::DONE, OneDriveFolderReview::SKIPPED], true)) {
            $review->fill(['candidates' => $candidates, 'standard_name' => $standard, 'scanned_at' => now()])->save();
        } else {
            $review->fill([
                'status' => $status,
                'candidates' => $candidates,
                'standard_name' => $standard,
                'error' => null,
                'scanned_at' => now(),
            ])->save();
        }

        return $review;
    }

    /**
     * What applying would do — nothing is changed here.
     *
     * @return list<array{action: string, from: ?string, to: string}> action: rename, create, keep
     */
    public function plan(OneDriveFolderReview $review, ?string $folderId): array
    {
        $standard = $review->standard_name;

        if ($folderId === null) {
            return [
                ['action' => 'create', 'from' => null, 'to' => $standard],
                ...array_map(fn (string $sub) => ['action' => 'create', 'from' => null, 'to' => $standard.'/'.$sub], $this->standardSubfolders($review->matter)),
            ];
        }

        $folder = collect($review->candidates ?? [])->firstWhere('id', $folderId);
        if ($folder === null) {
            throw new RuntimeException(__('That folder is no longer among those found — scan again.'));
        }

        $steps = [$folder['name'] === $standard
            ? ['action' => 'keep', 'from' => $folder['name'], 'to' => $standard]
            : ['action' => 'rename', 'from' => $folder['name'], 'to' => $standard]];

        foreach ($this->subfolderSteps($review->party, $folderId, $review->matter) as $step) {
            $steps[] = [...$step, 'from' => $step['from'] !== null ? $standard.'/'.$step['from'] : null, 'to' => $standard.'/'.$step['to']];
        }

        return $steps;
    }

    /**
     * Applies the plan for the folder chosen (null: make one) and links the
     * folder to the matter.
     */
    public function apply(OneDriveFolderReview $review, ?string $folderId, ?int $userId = null): OneDriveFolderReview
    {
        $party = $review->party;
        $user = (string) $party?->onedrive_email;
        $log = [];

        try {
            if (blank($user)) {
                throw new RuntimeException(__('No OneDrive account on :name\'s profile.', ['name' => $party?->name ?? '—']));
            }

            if ($folderId === null) {
                $item = $this->folders->makeFolder($party, $review->standard_name, MatterOneDriveFolders::subfolders($review->matter));
                $log[] = ['action' => 'create', 'from' => null, 'to' => $review->standard_name];
            } else {
                $folder = collect($review->candidates ?? [])->firstWhere('id', $folderId)
                    ?? throw new RuntimeException(__('That folder is no longer among those found — scan again.'));
                $item = ['id' => $folder['id'], 'webUrl' => $folder['webUrl']];

                if ($folder['name'] !== $review->standard_name) {
                    try {
                        $item = $this->client->rename($user, $folder['id'], $review->standard_name);
                    } catch (RuntimeException $e) {
                        throw new RuntimeException(str_contains($e->getMessage(), '409')
                            ? __('A folder named ":name" is there already — scan again and choose it.', ['name' => $review->standard_name])
                            : $e->getMessage());
                    }
                    $log[] = ['action' => 'rename', 'from' => $folder['name'], 'to' => $review->standard_name];
                }

                foreach ($this->subfolderSteps($party, $folder['id'], $review->matter) as $step) {
                    if ($step['action'] === 'rename') {
                        $this->client->rename($user, (string) $step['id'], $step['to']);
                    }

                    if ($step['action'] !== 'keep') {
                        // The whole path: "a/b" makes b inside a (kept when there).
                        $this->client->ensureFolder($user, $step['path'], $folder['id']);
                        $log[] = ['action' => $step['action'], 'from' => $step['from'], 'to' => $step['to']];
                    } elseif ($step['path'] !== $step['to']) {
                        $this->client->ensureFolder($user, $step['path'], $folder['id']);
                    }
                }
            }

            MatterOneDriveFolder::updateOrCreate(
                ['matter_id' => $review->matter_id, 'party_id' => $review->party_id],
                ['folder_name' => $review->standard_name, 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => $item['id'], 'web_url' => $item['webUrl'], 'error' => null],
            );

            $review->update([
                'status' => OneDriveFolderReview::DONE,
                'drive_item_id' => $item['id'],
                'web_url' => $item['webUrl'],
                'error' => null,
                'log' => $log,
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $review->update(['status' => OneDriveFolderReview::FAILED, 'error' => $e->getMessage(), 'log' => $log ?: null]);
        }

        return $review->refresh();
    }

    /**
     * The standard subfolders against what is in the folder: each kept
     * when there, renamed when there under another spelling of it ("2-
     * المستندات" for "02 المستندات"), or made.
     *
     * @return list<array{action: string, id: ?string, from: ?string, to: string, path: string}>
     */
    private function subfolderSteps(?Party $party, string $folderId, ?Matter $matter = null): array
    {
        $standard = $this->standardSubfolders($matter);
        if ($standard === [] || ! $party) {
            return [];
        }

        $existing = $this->client->childFolders((string) $party->onedrive_email, $folderId);
        $names = array_column($existing, 'name');
        $taken = [];
        $steps = [];

        foreach ($standard as $line) {
            $segments = OneDriveClient::segments($line);
            $top = MatterOneDriveFolders::clean($segments[0] ?? '');
            $path = implode('/', array_map([MatterOneDriveFolders::class, 'clean'], $segments));

            if ($top === '') {
                continue;
            }

            if (in_array($top, $names, true)) {
                $taken[] = $top;
                $steps[] = ['action' => 'keep', 'id' => null, 'from' => $top, 'to' => $top, 'path' => $path];

                continue;
            }

            // Another spelling: the same words once numbering, case and spacing are set aside.
            $match = collect($existing)->first(fn (array $f): bool => ! in_array($f['name'], $taken, true)
                && ! in_array($f['name'], array_map(fn ($l) => MatterOneDriveFolders::clean(OneDriveClient::segments($l)[0] ?? ''), $standard), true)
                && self::core($f['name']) !== '' && self::core($f['name']) === self::core($top));

            if ($match) {
                $taken[] = $match['name'];
                $names[] = $top;
                $steps[] = ['action' => 'rename', 'id' => $match['id'], 'from' => $match['name'], 'to' => $top, 'path' => $path];
            } else {
                $names[] = $top;
                $steps[] = ['action' => 'create', 'id' => null, 'from' => null, 'to' => $top, 'path' => $path];
            }
        }

        return $steps;
    }

    /**
     * @return list<string>
     */
    private function standardSubfolders(?Matter $matter = null): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $line): string => implode('/', array_map([MatterOneDriveFolders::class, 'clean'], OneDriveClient::segments($line))),
            MatterOneDriveFolders::subfolders($matter),
        ))));
    }

    /** A name's words without its numbering: "02 - المستندات" → "المستندات". */
    private static function core(string $name): string
    {
        $name = strtr($name, self::DIGITS);
        $name = preg_replace('/^[\d\s.\-_)(]+/u', '', $name) ?? '';

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

    /**
     * The assistant's active matters.
     *
     * @return Collection<int, Matter>
     */
    private function mattersOf(Party $assistant): Collection
    {
        return self::activeMatters()
            ->whereHas('matterParties', fn (Builder $q) => $q->where('party_id', $assistant->getKey())->where('role', 'expert')->where('type', 'assistant'))
            ->with(['type', 'court'])
            ->get();
    }
}
