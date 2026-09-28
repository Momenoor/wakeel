<?php

namespace App\Services\MMS;

use App\Jobs\CreateMatterOneDriveFolder;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
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
        $party = $folder->party;

        if (! $party instanceof Party || blank($party->onedrive_email)) {
            throw new \RuntimeException(__('No OneDrive account on :name\'s profile.', ['name' => $party?->name ?? '—']));
        }

        $parent = $this->client->ensureFolder($party->onedrive_email, (string) $party->onedrive_path);
        $item = $this->client->ensureFolder($party->onedrive_email, self::clean($folder->folder_name), $parent['id']);

        foreach (self::subfolders() as $subfolder) {
            $path = implode('/', array_map([self::class, 'clean'], OneDriveClient::segments($subfolder)));

            if ($path !== '') {
                $this->client->ensureFolder($party->onedrive_email, $path, $item['id']);
            }
        }

        $folder->update([
            'status' => MatterOneDriveFolder::CREATED,
            'drive_item_id' => $item['id'],
            'web_url' => $item['webUrl'],
            'error' => null,
        ]);
    }
}
