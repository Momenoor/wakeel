<?php

namespace App\Services\MMS;

use App\Jobs\CreateMatterOneDriveFolder;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * A folder for each matter in its assistant's OneDrive:
 *
 *   <assistant's path>/<2026-123 - matter type - court>/<standard subfolders>
 *
 * Made when an assistant is assigned to a matter created after the feature
 * was switched on — existing matters are not touched. The subfolder list
 * lives in the OneDrive settings; changing it affects only folders made
 * afterwards. Changing the assistant on a matter does nothing.
 */
class MatterOneDriveFolders
{
    public const ENABLED = 'onedrive_folders_enabled';

    public const ENABLED_AT = 'onedrive_folders_enabled_at';

    public const SUBFOLDERS = 'onedrive_subfolders';

    /** The subfolder of a matter's folder signed minutes go to. */
    public const SIGNED_MINUTES = 'onedrive_signed_minutes_folder';

    public function __construct(private readonly OneDriveClient $client) {}

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::ENABLED, false);
    }

    /**
     * Matters created from this moment get folders; set when the feature
     * is switched on.
     */
    public static function enabledAt(): ?Carbon
    {
        $at = Setting::get(self::ENABLED_AT);

        return filled($at) ? Carbon::parse($at) : null;
    }

    /**
     * The standard subfolders, one per line; "a/b" makes b inside a.
     *
     * @return list<string>
     */
    public static function subfolders(): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', (string) Setting::get(self::SUBFOLDERS, '')) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * "2026-123 - خبرة - محاكم دبي", without the characters OneDrive does
     * not allow in a name.
     */
    public static function folderName(Matter $matter): string
    {
        $matter->loadMissing(['type', 'court']);

        $parts = array_filter([
            trim($matter->year.'-'.$matter->number, '-'),
            $matter->type?->getAttribute('name'),
            $matter->court?->getAttribute('name'),
        ], 'filled');

        return self::clean(implode(' - ', $parts)) ?: 'Matter '.$matter->getKey();
    }

    public static function clean(string $name): string
    {
        $name = preg_replace('/["*:<>?\/\\\\|]+/u', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', (string) $name);

        // OneDrive also refuses a name ending in a dot or a space.
        return rtrim(trim((string) $name), '. ');
    }

    /**
     * Queues the folder for an assistant just added to a matter, when the
     * feature is on and the matter is new since it was switched on.
     */
    public static function queueFor(MatterParty $row): void
    {
        if ($row->role !== 'expert' || $row->type !== 'assistant' || ! $row->party_id || ! $row->matter_id) {
            return;
        }

        if (! self::enabled() || ($since = self::enabledAt()) === null) {
            return;
        }

        $matter = Matter::find($row->matter_id);

        if ($matter === null || $matter->created_at === null || $matter->created_at->lt($since)) {
            return;
        }

        self::queue($matter, $row->party_id);
    }

    /**
     * Queues (or re-queues after a failure) one assistant's folder for a
     * matter. A folder already made is left alone.
     */
    public static function queue(Matter $matter, int $partyId): MatterOneDriveFolder
    {
        $folder = MatterOneDriveFolder::firstOrNew(['matter_id' => $matter->getKey(), 'party_id' => $partyId]);

        if ($folder->exists && $folder->isCreated()) {
            return $folder;
        }

        $folder->fill(['folder_name' => self::folderName($matter), 'status' => MatterOneDriveFolder::PENDING, 'error' => null])->save();

        CreateMatterOneDriveFolder::dispatch($folder->getKey())->afterCommit();

        return $folder;
    }

    /**
     * Creates the folder and its standard subfolders in the assistant's
     * OneDrive. Folders already there are reused, never emptied.
     */
    public function create(MatterOneDriveFolder $folder): void
    {
        $item = $this->makeFolder($folder->party, $folder->folder_name);

        $folder->update([
            'status' => MatterOneDriveFolder::CREATED,
            'drive_item_id' => $item['id'],
            'web_url' => $item['webUrl'],
            'error' => null,
        ]);
    }

    /**
     * A folder with the standard subfolders in the assistant's OneDrive,
     * under the path on their profile. Folders already there are reused.
     *
     * @return array{id: string, webUrl: string}
     */
    public function makeFolder(?Party $party, string $name): array
    {
        if (! $party instanceof Party || blank($party->onedrive_email)) {
            throw new \RuntimeException(__('No OneDrive account on :name\'s profile.', ['name' => $party?->name ?? '—']));
        }

        $parent = $this->client->ensureFolder($party->onedrive_email, (string) $party->onedrive_path);
        $item = $this->client->ensureFolder($party->onedrive_email, self::clean($name), $parent['id']);

        foreach (self::subfolders() as $subfolder) {
            $path = implode('/', array_map([self::class, 'clean'], OneDriveClient::segments($subfolder)));

            if ($path !== '') {
                $this->client->ensureFolder($party->onedrive_email, $path, $item['id']);
            }
        }

        return $item;
    }

    public static function signedMinutesFolder(): string
    {
        return trim((string) Setting::get(self::SIGNED_MINUTES, '')) ?: 'محاضر موقعة';
    }

    /**
     * A signed copy of minutes, into the matter's folder in its assistant's
     * OneDrive — in the signed-minutes subfolder (made when missing). Null
     * when the matter has no folder.
     */
    public function uploadSignedMinutes(Matter $matter, string $name, string $contents, string $mime): ?string
    {
        $folder = MatterOneDriveFolder::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', MatterOneDriveFolder::CREATED)
            ->whereNotNull('drive_item_id')
            ->with('party')
            ->oldest('id')
            ->get()
            ->first(fn (MatterOneDriveFolder $f) => filled($f->party?->onedrive_email));

        if (! $folder) {
            return null;
        }

        $user = (string) $folder->party->onedrive_email;
        $path = implode('/', array_map([self::class, 'clean'], OneDriveClient::segments(self::signedMinutesFolder())));
        $target = $path !== '' ? $this->client->ensureFolder($user, $path, $folder->drive_item_id)['id'] : $folder->drive_item_id;

        return $this->client->upload($user, $target, self::clean(pathinfo($name, PATHINFO_FILENAME)).'.'.pathinfo($name, PATHINFO_EXTENSION), $contents, $mime)['webUrl'];
    }

    /** The test folder's name — fixed, so the test can find it to remove it. */
    public const TEST_FOLDER = 'Wakeel test folder';

    /**
     * Everyone matter folders can be made for: a OneDrive account on
     * their profile.
     *
     * @return Collection<int, Party>
     */
    public static function assistantsWithOneDrive(): Collection
    {
        return Party::query()
            ->whereNotNull('onedrive_email')
            ->where('onedrive_email', '!=', '')
            ->orderBy('name')
            ->get();
    }

    /**
     * The test folder, with the standard subfolders, in this assistant's
     * OneDrive — the same way a matter folder is made.
     *
     * @return array{id: string, webUrl: string}
     */
    public function createTestFolder(Party $party): array
    {
        return $this->makeFolder($party, self::TEST_FOLDER);
    }

    /**
     * Removes the test folder (to the assistant's OneDrive recycle bin).
     * False when there was none.
     */
    public function removeTestFolder(Party $party): bool
    {
        if (blank($party->onedrive_email)) {
            throw new \RuntimeException(__('No OneDrive account on :name\'s profile.', ['name' => $party->name]));
        }

        $path = implode('/', [...OneDriveClient::segments((string) $party->onedrive_path), self::TEST_FOLDER]);
        $folder = $this->client->findFolder($party->onedrive_email, $path);

        if ($folder === null) {
            return false;
        }

        $this->client->delete($party->onedrive_email, $folder['id']);

        return true;
    }
}
