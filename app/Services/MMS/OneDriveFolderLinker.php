<?php

namespace App\Services\MMS;

use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\OneDriveFolderReview;
use App\Models\Party;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * A matter from before its folders were made: its folder already in the
 * assistant's OneDrive, whatever it is called and wherever it is, linked
 * as it is — not renamed, nothing made in it — once, from the matter's
 * OneDrive section.
 */
class OneDriveFolderLinker
{
    /** How long an assistant's folders are kept while one is chosen. */
    private const LIST_SECONDS = 600;

    public function __construct(private readonly OneDriveClient $client) {}

    /**
     * The matter's assistants with a OneDrive whose folder isn't linked yet.
     *
     * @return Collection<int, Party>
     */
    public static function unlinked(Matter $matter): Collection
    {
        $linked = MatterOneDriveFolder::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', MatterOneDriveFolder::CREATED)
            ->pluck('party_id')
            ->all();

        return Party::query()
            ->whereIn('id', $matter->assistantsOnly()->pluck('party_id'))
            ->whereNotIn('id', $linked)
            ->whereNotNull('onedrive_email')
            ->where('onedrive_email', '!=', '')
            ->orderBy('name')
            ->get();
    }

    /**
     * The folders in the assistant's matters folder (their OneDrive's root
     * without one) — those with the matter's number and year first.
     *
     * @return array<string, string> id => name
     */
    public function choices(Matter $matter, Party $assistant): array
    {
        $folders = collect($this->folders($assistant));
        [$likely, $rest] = $folders->partition(fn (array $f): bool => OneDriveFolderReviewer::matches($f['name'], $matter->number, $matter->year));

        return $likely->mapWithKeys(fn (array $f): array => [$f['id'] => '★ '.$f['name']])
            ->merge($rest->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->mapWithKeys(fn (array $f): array => [$f['id'] => $f['name']]))
            ->all();
    }

    /**
     * Folders anywhere in the assistant's OneDrive with these words in
     * their name — with where each is.
     *
     * @return array<string, string> id => "name — where"
     */
    public function search(Party $assistant, string $words): array
    {
        $words = trim($words);
        if (mb_strlen($words) < 2) {
            return [];
        }

        // Their matters folder first, by name; then OneDrive's own search, anywhere.
        $found = collect($this->folders($assistant))
            ->filter(fn (array $f): bool => mb_stripos($f['name'], $words) !== false)
            ->mapWithKeys(fn (array $f): array => [$f['id'] => $f['name']]);

        $everywhere = collect($this->client->search((string) $assistant->onedrive_email, 'root', $words))
            ->filter(fn (array $item): bool => $item['folder'])
            ->mapWithKeys(fn (array $item): array => [$item['id'] => $item['name'].' — '.self::where($item['path'])]);

        return $found->union($everywhere)->take(50)->all();
    }

    /** A folder's name, for the one chosen. */
    public function label(Party $assistant, string $id): ?string
    {
        $known = collect($this->folders($assistant))->firstWhere('id', $id);

        return $known['name'] ?? rescue(fn () => $this->client->itemInfo((string) $assistant->onedrive_email, $id)['name'], null, false);
    }

    /** The folder linked as the matter's, in that assistant's OneDrive — as it is. */
    public function link(Matter $matter, Party $assistant, string $itemId): MatterOneDriveFolder
    {
        if (blank($assistant->onedrive_email)) {
            throw new RuntimeException(__('No OneDrive account on :name\'s profile.', ['name' => $assistant->name]));
        }

        $item = $this->client->itemInfo((string) $assistant->onedrive_email, $itemId);
        if (! $item['folder']) {
            throw new RuntimeException(__('":name" is a file, not a folder.', ['name' => $item['name']]));
        }

        $folder = MatterOneDriveFolder::updateOrCreate(
            ['matter_id' => $matter->getKey(), 'party_id' => $assistant->getKey()],
            ['folder_name' => $item['name'], 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => $item['id'], 'web_url' => $item['webUrl'], 'error' => null],
        );

        // The review of the OneDrive Folders page: decided.
        OneDriveFolderReview::query()
            ->where('matter_id', $matter->getKey())
            ->where('party_id', $assistant->getKey())
            ->update([
                'status' => OneDriveFolderReview::DONE,
                'drive_item_id' => $item['id'],
                'web_url' => $item['webUrl'],
                'error' => null,
                'log' => json_encode([['action' => 'link', 'from' => null, 'to' => $item['name']]], JSON_UNESCAPED_UNICODE),
                'decided_at' => now(),
                'decided_by' => auth()->id(),
            ]);

        return $folder;
    }

    /**
     * @return list<array{id: string, name: string, webUrl: string}>
     */
    private function folders(Party $assistant): array
    {
        return Cache::remember('onedrive-link:'.$assistant->getKey(), self::LIST_SECONDS, function () use ($assistant): array {
            $user = (string) $assistant->onedrive_email;
            $base = filled($assistant->onedrive_path) ? $this->client->findFolder($user, (string) $assistant->onedrive_path) : null;

            return $this->client->childFolders($user, $base['id'] ?? null);
        });
    }

    /** "/drive/root:/Work/2026" as "Work/2026". */
    private static function where(string $path): string
    {
        return trim((string) preg_replace('~^/drive/root:?~', '', $path), '/') ?: '/';
    }
}
