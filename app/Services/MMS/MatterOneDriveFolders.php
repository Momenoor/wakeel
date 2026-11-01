<?php

namespace App\Services\MMS;

use App\Enums\NotificationType;
use App\Filament\Mms\Pages\OneDriveSettings;
use App\Jobs\CreateMatterOneDriveFolder;
use App\Jobs\MoveMatterOneDriveFolder;
use App\Models\Attachment;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFile;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\MinutesDelivery;
use App\Models\OneDriveFolderReview;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use App\Services\Notifications\Notifier;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

    /** Where every email sent from a matter is kept, as a PDF; empty: not kept there. */
    public const SENT_EMAILS = 'onedrive_sent_emails_folder';

    /** Where the replies to them are kept, with what they came with; empty: not kept there. */
    public const RECEIVED_EMAILS = 'onedrive_received_emails_folder';

    /** Setting: the standard name of the folder at the top of each assistant's OneDrive that holds their matters in progress. */
    public const MATTERS_FOLDER = 'onedrive_matters_folder_name';

    /** Setting: the standard name of the folder at the top of each assistant's OneDrive for their finished matters. */
    public const FINISHED_FOLDER = 'onedrive_finished_folder_name';

    /** Setting: the folder for the emails sent to a creditor (or its agent) — with {{creditor}} and {{creditor.number}}; empty: the sent emails folder. */
    public const SENT_TO_CREDITORS = 'onedrive_sent_creditors_folder';

    /** In an emails' folder structure: {{serial}}, the email's number among those filed under the same folder — the emails of a day 1, 2, 3. */
    public const SERIAL = 'serial';

    /** What {{serial}} is resolved to until the number is known (a character no name has). */
    private const SERIAL_MARK = "\u{E000}serial\u{E000}";

    public function __construct(private readonly OneDriveClient $client) {}

    /**
     * The matter's OneDrive folders a user may see: every one for a super admin, else their own (as the
     * assistant) and those of the assistants they administer (User::ownMatterPartyIds()).
     *
     * @return Collection<int, MatterOneDriveFolder>
     */
    public static function visibleTo(Matter $matter, ?User $user): Collection
    {
        return $matter->oneDriveFolders()
            ->with('party')
            ->when(! ($user?->isSuperAdmin() ?? false), fn ($q) => $q->whereIn('party_id', $user?->ownMatterPartyIds() ?? []))
            ->get();
    }

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
     * The standard subfolders, one per line; "a/b" makes b inside a — the
     * matter type's own structure when it has one, else the default. A matter
     * whose phase has folders of its own (Type::phaseFolders()): those, inside
     * a folder of the phase's name, instead of the lines that start with the
     * phase — the others are every phase's.
     *
     * @return list<string>
     */
    public static function subfolders(Matter|Type|null $for = null): array
    {
        $type = $for instanceof Matter ? $for->loadMissing('type')->type : $for;
        $own = $type instanceof Type ? self::lines((string) $type->onedrive_subfolders) : [];
        $lines = $own !== [] ? $own : self::lines((string) Setting::get(self::SUBFOLDERS, ''));
        $phase = $for instanceof Matter && $type instanceof Type ? $type->phaseFolders($for->phase) : [];

        if ($phase === []) {
            return $lines;
        }

        return [
            ...array_filter($lines, fn (string $line): bool => ! preg_match(self::PHASE_FIRST, OneDriveClient::segments($line)[0] ?? '')),
            ...array_map(fn (string $line): string => self::PHASE.'/'.$line, $phase),
        ];
    }

    /** The placeholder of the matter's phase — the folder a phase's own folders are made in. */
    public const PHASE = '{{matter.phase}}';

    /** A folder named by the matter's phase. */
    private const PHASE_FIRST = '/\{\{\s*matter\.phase\s*\}\}/u';

    /**
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $text) ?: []),
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
        // Broken bytes made readable first (a /u pattern gives up on them), then control characters out:
        // OneDrive refuses those as "illegal characters" too (a badly decoded subject carries them).
        $name = preg_replace('/[\p{Cc}"*:<>?\/\\\\|]+/u', ' ', mb_scrub($name, 'UTF-8'));
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
     *
     * First it looks for the matter's folder under another name (by its
     * number and year, as the review does): one found is not duplicated —
     * the folder waits for the office's decision in the review (OneDrive
     * Folders page), and those who manage OneDrive are told.
     */
    public function create(MatterOneDriveFolder $folder): void
    {
        $folder->loadMissing(['matter', 'party']);
        $review = null;

        // Another phase of a case that already has its main folder: it goes in that one, in a
        // subfolder of its own (named by the standard structure, e.g. its phase).
        if ($folder->matter && $folder->party && filled($folder->party->onedrive_email) && ($shared = self::sharedFolderFor($folder))) {
            $this->makeSubfolders($folder->party, (string) $shared->drive_item_id, self::subfoldersFor($folder->matter));

            $folder->update([
                'status' => MatterOneDriveFolder::CREATED,
                'drive_item_id' => $shared->drive_item_id,
                'web_url' => $shared->web_url,
                'folder_name' => $shared->folder_name,
                'error' => null,
            ]);

            return;
        }

        if ($folder->matter && $folder->party && filled($folder->party->onedrive_email)) {
            $review = app(OneDriveFolderReviewer::class)->check($folder->matter, $folder->party);
            $candidates = $review->candidates ?? [];
            $onlyTheStandardOne = count($candidates) === 1 && $candidates[0]['name'] === self::clean($folder->folder_name);

            if ($candidates !== [] && ! $onlyTheStandardOne) {
                $folder->update([
                    'status' => MatterOneDriveFolder::PENDING,
                    'error' => __('A folder for this matter is already in OneDrive (:names) — decide in Settings → OneDrive Folders.', [
                        'names' => collect($candidates)->pluck('name')->implode('، '),
                    ]),
                ]);
                $this->tellManagers($folder, $candidates);

                return;
            }
        }

        $item = $this->makeFolder($folder->party, $folder->folder_name, self::subfoldersFor($folder->matter));

        $folder->update([
            'status' => MatterOneDriveFolder::CREATED,
            'drive_item_id' => $item['id'],
            'web_url' => $item['webUrl'],
            'error' => null,
        ]);

        $review?->update([
            'status' => OneDriveFolderReview::DONE,
            'drive_item_id' => $item['id'],
            'web_url' => $item['webUrl'],
            'log' => [['action' => $review->candidates ? 'keep' : 'create', 'from' => null, 'to' => $folder->folder_name]],
            'decided_at' => now(),
        ]);
    }

    /**
     * Those who manage the OneDrive folders hear that a new matter's folder
     * is waiting for them.
     *
     * @param  list<array{id: string, name: string, webUrl: string}>  $candidates
     */
    private function tellManagers(MatterOneDriveFolder $folder, array $candidates): void
    {
        try {
            // Roles and permissions loaded at once: asked of every user here, and again for their preferences.
            $managers = User::query()->with(['roles.permissions', 'permissions'])->get()->filter(fn (User $user): bool => $user->can('View:OneDriveSettings'));

            if ($managers->isEmpty()) {
                return;
            }

            $matter = $folder->matter?->number.'/'.$folder->matter?->year;
            $url = OneDriveSettings::getUrl(panel: 'mms');

            Notifier::send(
                NotificationType::ONEDRIVE_FOLDER_REVIEW,
                $managers,
                __('A OneDrive folder for :matter is already there', ['matter' => $matter]),
                __(':assistant has :names — no new folder was made. Decide whether to standardise and link it.', [
                    'assistant' => $folder->party?->name,
                    'names' => collect($candidates)->pluck('name')->implode('، '),
                ]),
                $url,
                fn (Notification $notification) => $notification
                    ->warning()
                    ->icon('heroicon-o-folder-open')
                    ->iconColor('warning')
                    ->actions([
                        Action::make('review')->label('Review')->translateLabel(false)->url($url)->markAsRead(),
                    ]),
            );
        } catch (Throwable $e) {
            Log::info('OneDrive folder notice not sent', ['folder' => $folder->getKey(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * A folder with the standard subfolders in the assistant's OneDrive,
     * under the path on their profile. Folders already there are reused.
     *
     * @param  ?list<string>  $subfolders  the matter type's structure (the default when null)
     * @return array{id: string, webUrl: string}
     */
    public function makeFolder(?Party $party, string $name, ?array $subfolders = null): array
    {
        if (! $party instanceof Party || blank($party->onedrive_email)) {
            throw new \RuntimeException(__('No OneDrive account on :name\'s profile.', ['name' => $party?->name ?? '—']));
        }

        $parent = $this->client->ensureFolder($party->onedrive_email, (string) $party->onedrive_path);
        $item = $this->client->ensureFolder($party->onedrive_email, self::clean($name), $parent['id']);

        $this->makeSubfolders($party, $item['id'], $subfolders ?? self::subfolders());

        return $item;
    }

    /**
     * The standard subfolders inside a folder (by id); those already there are reused.
     *
     * @param  list<string>  $subfolders
     */
    /**
     * A matter's phase changed: its standard folders, the new phase's among them, made in each of its folders not yet
     * moved to the finished matters — those already there left as they are.
     */
    public function makePhaseFolders(Matter $matter): int
    {
        $made = 0;

        MatterOneDriveFolder::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', MatterOneDriveFolder::CREATED)
            ->whereNotNull('drive_item_id')
            ->whereNull('moved_at')
            ->with('party')
            ->get()
            ->filter(fn (MatterOneDriveFolder $folder): bool => filled($folder->party?->onedrive_email))
            ->each(function (MatterOneDriveFolder $folder) use ($matter, &$made): void {
                $this->makeSubfolders($folder->party, (string) $folder->drive_item_id, self::subfoldersFor($matter));
                $made++;
            });

        return $made;
    }

    private function makeSubfolders(Party $party, string $folderId, array $subfolders): void
    {
        foreach ($subfolders as $subfolder) {
            $path = self::cleanPath($subfolder);

            if ($path !== '') {
                $this->client->ensureFolder($party->onedrive_email, $path, $folderId);
            }
        }
    }

    /**
     * The folder of another matter of the same case that this assistant already has: the
     * same year and number (however the number is written), or the same parent matter
     * (its parent, its children, or its siblings). A new phase of a case is put in that
     * main folder, not in one of its own. A folder already moved to the finished
     * matters is not one to join.
     */
    public static function sharedFolderFor(MatterOneDriveFolder $folder): ?MatterOneDriveFolder
    {
        $matter = $folder->matter;

        if (! $matter) {
            return null;
        }

        $ids = collect(MatterReferenceMatcher::idsOf((string) $matter->number, (int) $matter->year));

        // Top-level matters have a parent id of null — or 0, in older data.
        if (filled($matter->parent_id)) {
            $ids->push((int) $matter->parent_id);
            $ids = $ids->merge(Matter::query()->where('parent_id', $matter->parent_id)->pluck('id'));
        }
        $ids = $ids->merge(Matter::query()->where('parent_id', $matter->getKey())->pluck('id'));

        $ids = $ids->map(fn ($id) => (int) $id)->reject(fn (int $id): bool => $id === (int) $matter->getKey())->unique()->values();

        if ($ids->isEmpty()) {
            return null;
        }

        return MatterOneDriveFolder::query()
            ->where('party_id', $folder->party_id)
            ->where('status', MatterOneDriveFolder::CREATED)
            ->whereNotNull('drive_item_id')
            ->whereNull('moved_at')
            ->whereIn('matter_id', $ids)
            ->oldest('id')
            ->first();
    }

    /**
     * What of these emails that came in is in the matter's OneDrive folder, read
     * from OneDrive itself: the PDF and the original (.eml) in the received-emails
     * folder, and how many of the files it came with in the folder of the PDF's
     * name beside them. Kept on each email. False when there is nothing to read
     * them from — no OneDrive folder for the matter, or no folder set for the
     * received emails.
     *
     * @param  iterable<MatterEmail>  $emails
     */
    public function checkEmails(Matter $matter, iterable $emails): bool
    {
        $folder = self::folderFor($matter);

        if (! $folder || self::receivedEmailsTemplate($matter) === '') {
            return false;
        }

        $user = (string) $folder->party->onedrive_email;
        $apartListings = [];

        // Emails saved in the same folder (a day's, a side's, a report's) are read from it together.
        $byFolder = collect($emails)->groupBy(fn (MatterEmail $email): string => self::emailFolder($email, $matter));

        foreach ($byFolder as $received => $inFolder) {
            $id = $this->walk($user, (string) $folder->drive_item_id, (string) $received);
            $listing = $id !== null ? collect($this->client->children($user, $id)) : collect();
            $names = $listing->where('folder', false)->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->flip();
            $folders = $listing->where('folder', true)->keyBy(fn (array $f): string => mb_strtolower($f['name']));

            foreach ($inFolder as $email) {
                $base = pathinfo((string) ($email->files[0]['name'] ?? ''), PATHINFO_FILENAME);
                $stem = $base !== '' ? mb_strtolower(MatterOneDriveExplorer::cleanName($base)) : null;

                // The files it came with, by the names they were put in OneDrive under.
                $expected = collect($email->attachmentFiles())->map(fn (array $file): string => mb_strtolower(self::oneDriveName((string) $file['name'])));
                $apart = (string) (((array) $email->onedrive)['attachments_folder'] ?? '');
                if ($apart !== '') {
                    // Kept apart, in an attachments folder: read once a folder.
                    $apartListings[$apart] ??= ($apartId = $this->walk($user, (string) $folder->drive_item_id, $apart)) !== null
                        ? collect($this->client->children($user, $apartId))->where('folder', false)->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->flip()
                        : collect();
                    $inside = $apartListings[$apart];
                } else {
                    $inside = $stem !== null && $expected->isNotEmpty() && ($sub = $folders->get(mb_strtolower(self::clean($base))))
                        ? collect($this->client->children($user, $sub['id']))->where('folder', false)->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->flip()
                        : collect();
                }

                // Where it is (a report's, …) kept with it.
                $email->update(['onedrive' => [
                    ...array_intersect_key((array) $email->onedrive, array_flip(['folder', 'attachments_folder', 'report', 'before_report', 'added'])),
                    'pdf' => $stem !== null && $names->has($stem.'.pdf'),
                    'eml' => $stem !== null && $names->has($stem.'.eml'),
                    'attachments' => $expected->filter(fn (string $name): bool => $inside->has($name))->count(),
                    'checked_at' => now()->toDateTimeString(),
                ]]);
            }
        }

        return true;
    }

    /**
     * A received email's files in OneDrive — its PDF, its original (.eml) and the folder of the files
     * it came with — moved from one matter's received-emails folder to the other's. Moved as they are
     * when both folders are in the same person's OneDrive; else downloaded, saved there, and removed.
     * What is in the new folder after.
     *
     * @return array{pdf: bool, eml: bool, attachments: int, folder: string, moved: bool}
     */
    public function moveEmailFiles(MatterEmail $email, Matter $from, Matter $to): array
    {
        // In the folder of its sender's side on the other matter.
        $address = MatterEmail::addressOf((string) $email->from);
        $side = ReceivedEmailFiling::side($to, $address);
        $targetPath = self::receivedEmailsFolderFor($to, $email->at, $side, $address);
        $apartPath = self::attachmentsFolderFor($to, $email->at, $side, $address, MatterReplyCollector::partyName($address) ?? $address);
        $result = ['pdf' => false, 'eml' => false, 'attachments' => 0, 'folder' => $targetPath, 'attachments_folder' => $apartPath ?: null, 'moved' => false];
        $apartId = null;
        $source = self::folderFor($from);
        $target = self::folderFor($to);
        $base = pathinfo((string) ($email->files[0]['name'] ?? ''), PATHINFO_FILENAME);

        if (! $source || ! $target || $base === '') {
            return $result;
        }

        $sourceUser = (string) $source->party->onedrive_email;
        $targetUser = (string) $target->party->onedrive_email;
        $items = $this->emailItems($email, $from);

        if ($items->isEmpty()) {
            return $result;
        }

        $targetId = $this->ensurePath($targetUser, $target, $targetPath);

        foreach ($items as $item) {
            // A file kept apart: to the other matter's attachments folder — else beside its PDF, in a folder of its name.
            if ($item['apart'] ?? false) {
                $apartId ??= $apartPath !== '' ? $this->ensurePath($targetUser, $target, $apartPath) : (string) $this->client->ensureFolder($targetUser, self::clean($base), $targetId)['id'];

                if ($sourceUser === $targetUser) {
                    $this->client->move($sourceUser, $item['id'], $apartId);
                } else {
                    $this->copyFile($sourceUser, $item, $targetUser, $apartId);
                    $this->client->delete($sourceUser, $item['id']);
                }

                $result['attachments']++;

                continue;
            }

            if ($item['folder']) {
                $files = collect($this->client->children($sourceUser, $item['id']))->where('folder', false);
                $result['attachments'] = $files->count();

                if ($sourceUser === $targetUser) {
                    $this->client->move($sourceUser, $item['id'], $targetId);
                } else {
                    $newId = $this->client->ensureFolder($targetUser, self::clean($base), $targetId)['id'];
                    $files->each(fn (array $file) => $this->copyFile($sourceUser, $file, $targetUser, $newId));
                    $this->client->delete($sourceUser, $item['id']);
                }

                continue;
            }

            if ($sourceUser === $targetUser) {
                $this->client->move($sourceUser, $item['id'], $targetId);
            } else {
                $this->copyFile($sourceUser, $item, $targetUser, $targetId);
                $this->client->delete($sourceUser, $item['id']);
            }

            $result[str_ends_with(mb_strtolower($item['name']), '.eml') ? 'eml' : 'pdf'] = true;
        }

        $result['moved'] = true;

        return $result;
    }

    /**
     * A received email's files removed from the matter's OneDrive (to its owner's recycle bin): its
     * PDF, its original and the folder of the files it came with. How many items were removed.
     */
    public function removeEmailFiles(MatterEmail $email, Matter $matter): int
    {
        $folder = self::folderFor($matter);
        $items = $this->emailItems($email, $matter);

        $items->each(fn (array $item) => $this->client->delete((string) $folder->party->onedrive_email, $item['id']));

        return $items->count();
    }

    /**
     * An email's files moved to another folder of the same matter's OneDrive folder — a creditor's, a periodic
     * report's — and that folder kept with it, as where it now is. False when none of it was found to move.
     *
     * @param  array<string, mixed>  $note  noted with it too
     */
    public function moveEmailWithin(MatterEmail $email, Matter $matter, string $targetPath, array $note = []): bool
    {
        $folder = self::folderFor($matter);
        $items = $this->emailItems($email, $matter);

        if (! $folder || $items->isEmpty()) {
            return false;
        }

        $user = (string) $folder->party->onedrive_email;
        $targetId = $this->ensurePath($user, $folder, $targetPath);
        // The files kept apart (an attachments folder: the party's documents) stay where they are.
        $items->reject(fn (array $item): bool => $item['apart'] ?? false)->each(fn (array $item) => $this->client->move($user, $item['id'], $targetId));

        // With what else is to be noted of where it is (the report it went in) — a null taken off.
        $email->update(['onedrive' => array_filter([...(array) $email->onedrive, ...$note, 'folder' => $targetPath], fn ($value): bool => $value !== null)]);

        return true;
    }

    /**
     * Files of a folder of the matter's OneDrive folder, known by their links (a signed copy of minutes), moved to
     * another folder of it — their new links by the old; those not found there left out.
     *
     * @param  list<string>  $urls
     * @return array<string, string>
     */
    public function moveFilesByUrl(Matter $matter, array $urls, string $fromPath, string $toPath): array
    {
        $folder = self::folderFor($matter);
        $urls = array_values(array_filter($urls));

        if (! $folder || $urls === []) {
            return [];
        }

        $user = (string) $folder->party->onedrive_email;
        $fromId = $this->walk($user, (string) $folder->drive_item_id, $fromPath);
        $found = $fromId === null ? collect() : collect($this->client->children($user, $fromId))
            ->filter(fn (array $item): bool => ! $item['folder'] && in_array($item['webUrl'], $urls, true));

        if ($found->isEmpty()) {
            return [];
        }

        $targetId = $this->ensurePath($user, $folder, $toPath);

        return $found->mapWithKeys(fn (array $item): array => [$item['webUrl'] => $this->client->move($user, $item['id'], $targetId)['webUrl']])->all();
    }

    /**
     * An email's items where it is in the matter's OneDrive folder (emailFolder()): its PDF, its original
     * (.eml) and the folder of the files it came with — by the PDF's name. None when not found.
     *
     * @return \Illuminate\Support\Collection<int, array{id: string, name: string, folder: bool}>
     */
    private function emailItems(MatterEmail $email, Matter $matter): \Illuminate\Support\Collection
    {
        $folder = self::folderFor($matter);
        $base = pathinfo((string) ($email->files[0]['name'] ?? ''), PATHINFO_FILENAME);

        if (! $folder || $base === '') {
            return collect();
        }

        $user = (string) $folder->party->onedrive_email;
        $folderId = $this->walk($user, (string) $folder->drive_item_id, self::emailFolder($email, $matter));
        $stem = mb_strtolower(self::clean($base));

        $items = $folderId === null ? collect() : collect($this->client->children($user, $folderId))
            ->filter(fn (array $item): bool => in_array(mb_strtolower($item['name']), $item['folder'] ? [$stem] : [$stem.'.pdf', $stem.'.eml', $stem.'.msg'], true))
            ->map(fn (array $item): array => [...$item, 'apart' => false]);

        // Its files kept apart, in an attachments folder: each by its name there.
        $apart = (string) (((array) $email->onedrive)['attachments_folder'] ?? '');
        if ($apart !== '' && ($apartId = $this->walk($user, (string) $folder->drive_item_id, $apart)) !== null) {
            $names = collect($email->attachmentFiles())->map(fn (array $file): string => mb_strtolower(self::oneDriveName((string) $file['name'])))->flip();
            $items = $items->concat(collect($this->client->children($user, $apartId))
                ->filter(fn (array $item): bool => ! $item['folder'] && $names->has(mb_strtolower($item['name'])))
                ->map(fn (array $item): array => [...$item, 'apart' => true]));
        }

        return $items->values();
    }

    /** A subfolder of the matter's folder ("a/b"), made when missing — its id; the matter's folder itself for none. */
    private function ensurePath(string $user, MatterOneDriveFolder $folder, string $subfolder): string
    {
        $path = self::cleanPath($subfolder);

        return $path !== '' ? (string) $this->client->ensureFolder($user, $path, $folder->drive_item_id)['id'] : (string) $folder->drive_item_id;
    }

    /**
     * A folder's id, down a path from a folder — null when a folder on the way is not there. With $listings, the
     * folders listed are remembered in it: many paths walked down one tree ask OneDrive for each folder once.
     *
     * @param  array<string, list<array{id: string, name: string, webUrl: string}>>  $listings
     */
    public function walk(string $user, string $id, string $path, array &$listings = []): ?string
    {
        foreach (OneDriveClient::segments($path) as $segment) {
            $listings[$id] ??= $this->client->childFolders($user, $id);
            $next = collect($listings[$id])->first(fn (array $f): bool => mb_strtolower($f['name']) === mb_strtolower(self::clean($segment)));
            $id = (string) ($next['id'] ?? '');

            if ($id === '') {
                return null;
            }
        }

        return $id;
    }

    /** A file saved again in another person's OneDrive. */
    private function copyFile(string $fromUser, array $file, string $toUser, string $folderId): void
    {
        $info = $this->client->itemInfo($fromUser, $file['id']);
        $this->client->upload($toUser, $folderId, $file['name'], $this->client->download((string) $info['downloadUrl']), (string) ($file['mime'] ?? 'application/octet-stream'));
    }

    /** A file's name as it is put in OneDrive (no "/" or other characters it refuses). */
    private static function oneDriveName(string $name): string
    {
        [$base, $extension] = SentEmailArchive::splitName($name);

        return SentEmailArchive::cleanBase($base).($extension !== '' ? '.'.$extension : '');
    }

    /**
     * Queues the move of a finished matter's folders (one for each assistant who
     * has one and a folder for finished matters on their profile).
     */
    public static function queueMoveToFinished(Matter $matter): void
    {
        MatterOneDriveFolder::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', MatterOneDriveFolder::CREATED)
            ->whereNotNull('drive_item_id')
            ->whereNull('moved_at')
            ->whereHas('party', fn ($q) => $q->whereNotNull('onedrive_email')->where('onedrive_email', '!=', '')->whereNotNull('onedrive_finished_path')->where('onedrive_finished_path', '!=', ''))
            ->pluck('id')
            ->each(fn ($id) => MoveMatterOneDriveFolder::dispatch($id)->afterCommit());
    }

    /**
     * Moves a finished matter's folder to its assistant's folder for finished matters
     * (made when missing) and points the matter's link at the new place. The folder
     * keeps its id, so everything linked to it by id keeps working.
     *
     * A main folder shared with a matter still in progress (another phase of the case)
     * stays where it is; only this matter's own phase folders go — into a main folder
     * of the same name under the finished matters (made when missing) — and its link
     * follows them. When the last matter of the case is finished, what is left of the
     * main folder goes there too. False when there is nothing to do: not made yet,
     * already moved, or no folder for finished matters on the assistant's profile.
     */
    public function moveToFinished(MatterOneDriveFolder $folder): bool
    {
        $folder->loadMissing(['party', 'matter']);
        $party = $folder->party;

        if (! $folder->isCreated() || blank($folder->drive_item_id) || $folder->moved_at !== null
            || ! $party || blank($party->onedrive_email) || blank($party->onedrive_finished_path)) {
            return false;
        }

        $user = (string) $party->onedrive_email;
        $target = $this->client->ensureFolder($user, (string) $party->onedrive_finished_path);

        // The others using this main folder: those still in progress decide what is moved.
        $others = MatterOneDriveFolder::query()
            ->where('party_id', $folder->party_id)
            ->where('drive_item_id', $folder->drive_item_id)
            ->whereKeyNot($folder->getKey())
            ->whereNull('moved_at')
            ->with('matter')
            ->get();
        $inProgress = $others->filter(fn (MatterOneDriveFolder $other): bool => $other->matter !== null && $other->matter->final_report_at === null);

        if ($inProgress->isNotEmpty()) {
            $this->movePhaseOnly($folder, $user, $target['id']);

            return true;
        }

        $name = self::clean($folder->folder_name);
        $inFinished = collect($this->client->childFolders($user, $target['id']))->first(fn (array $f): bool => mb_strtolower($f['name']) === mb_strtolower($name));

        if ($inFinished === null) {
            // The whole folder; every finished matter of the case that shares it follows.
            $moved = $this->client->move($user, $folder->drive_item_id, $target['id']);
            $into = ['id' => $moved['id'] ?: $folder->drive_item_id, 'webUrl' => $moved['webUrl'] ?: $folder->web_url, 'name' => $moved['name'] ?: $folder->folder_name];
        } else {
            // A main folder of this case is there already (the earlier phases went into it): what is left goes in too.
            $this->mergeInto($user, (string) $folder->drive_item_id, $inFinished['id']);
            $into = ['id' => $inFinished['id'], 'webUrl' => $inFinished['webUrl'], 'name' => $inFinished['name']];
        }

        $old = $folder->drive_item_id;
        foreach ([$folder, ...$others->all()] as $row) {
            if ($row->drive_item_id === $old) {
                $row->update([
                    'drive_item_id' => $into['id'],
                    'web_url' => $into['webUrl'] ?: $row->web_url,
                    'folder_name' => $into['name'] ?: $row->folder_name,
                    'moved_at' => now(),
                    'move_error' => null,
                ]);
            }
        }

        return true;
    }

    /**
     * Only this matter's phase folders — the first folder of each line of the standard
     * structure that holds a placeholder, e.g. "{{matter.custom.Phase}}" — go to a main
     * folder of the same name under the finished matters; the main folder stays with
     * the matters still in progress. The matter's link follows them.
     */
    private function movePhaseOnly(MatterOneDriveFolder $folder, string $user, string $finishedParentId): void
    {
        $matter = $folder->matter;
        $phases = $matter ? self::phaseFolders($matter) : [];

        if ($phases === []) {
            throw new \RuntimeException(__('This main folder is shared with a matter still in progress, and this matter has no folder of its own in it to move (a folder named by a placeholder, like its phase, in the folder structure).'));
        }

        $main = $this->client->ensureFolder($user, self::clean($folder->folder_name), $finishedParentId);
        $inside = collect($this->client->childFolders($user, (string) $folder->drive_item_id))->keyBy(fn (array $f): string => mb_strtolower($f['name']));
        // The folders of the phase the next phase goes on working in (the type's): left with the matters in progress.
        $kept = array_map(fn (string $line): string => mb_strtolower(self::clean(OneDriveClient::segments($line)[0] ?? '')), $matter?->loadMissing('type')->type?->phaseKeepFolders() ?? []);

        foreach ($phases as $phase) {
            if (! $found = $inside->get(mb_strtolower($phase))) {
                continue;
            }

            if ($kept === []) {
                $this->client->move($user, $found['id'], $main['id']);

                continue;
            }

            // What is not kept goes, into a folder of the phase's name under the finished main folder; the phase's
            // folder stays here with the folders kept.
            $target = $this->client->ensureFolder($user, (string) $found['name'], $main['id']);
            foreach ($this->client->children($user, (string) $found['id']) as $child) {
                if (! in_array(mb_strtolower((string) $child['name']), $kept, true)) {
                    $this->client->move($user, (string) $child['id'], (string) $target['id']);
                }
            }
        }

        $folder->update([
            'drive_item_id' => $main['id'],
            'web_url' => $main['webUrl'] ?: $folder->web_url,
            'moved_at' => now(),
            'move_error' => null,
        ]);
    }

    /**
     * What is in a folder moved into another, and the emptied folder removed (to the
     * OneDrive recycle bin) — only when nothing is left in it.
     */
    private function mergeInto(string $user, string $fromId, string $toId): void
    {
        foreach ($this->client->children($user, $fromId) as $child) {
            $this->client->move($user, $child['id'], $toId);
        }

        if ($this->client->children($user, $fromId) === []) {
            $this->client->delete($user, $fromId);
        }
    }

    /**
     * The folders at the top of a matter's folder that are its own because their name
     * comes from the matter (its phase …): the first folder of each line of the standard
     * structure, and of the received emails structure, that holds a placeholder — a day,
     * month or year, which are the emails', do not count.
     *
     * @return list<string>
     */
    public static function phaseFolders(Matter $matter): array
    {
        $values = BulkMailPlaceholders::forMatter($matter);

        return array_values(array_unique(array_filter(array_map(
            fn (string $first): string => self::resolveParts($first, $matter, $values)[0] ?? '',
            self::namedFirsts($matter),
        ))));
    }

    /**
     * The first folders of the structure named after the matter (phaseFolders()), each as the structure writes
     * it — with its placeholder: to say what a folder was called before the matter changed.
     *
     * @return list<string>
     */
    private static function namedFirsts(Matter $matter): array
    {
        $firsts = [];

        foreach ([...self::subfolders($matter), self::receivedEmailsTemplate($matter)] as $line) {
            $first = OneDriveClient::segments($line)[0] ?? '';

            if (str_contains($first, '{{') && ! preg_match('/\{\{\s*(day|month|year)\s*\}\}/iu', $first)) {
                $firsts[] = $first;
            }
        }

        return array_values(array_unique($firsts));
    }

    /**
     * A matter's phase changed: the folders named after its phase, in each of its OneDrive folders, renamed from
     * the old phase's name to the new — what is in them with them — and every path the system keeps under them
     * changed (renameWithin()). What was renamed, old => new.
     *
     * @return array<string, string>
     */
    public function renamePhaseFolders(Matter $matter, string $fromPhase): array
    {
        $values = BulkMailPlaceholders::forMatter($matter);
        $before = [...$values, 'matter.phase' => $fromPhase];
        $renames = [];

        foreach (self::namedFirsts($matter) as $first) {
            $old = self::resolveParts($first, $matter, $before)[0] ?? '';
            $new = self::resolveParts($first, $matter, $values)[0] ?? '';

            if ($old !== '' && $new !== '' && $old !== $new) {
                $renames[$old] = $new;
            }
        }

        foreach ($renames as $old => $new) {
            $this->renameWithin($matter, $old, $new);
        }

        return $renames;
    }

    public static function signedMinutesFolder(): string
    {
        return trim((string) Setting::get(self::SIGNED_MINUTES, '')) ?: 'محاضر موقعة';
    }

    /** Where received emails go when the setting has not been changed. */
    public const DEFAULT_RECEIVED_EMAILS = 'المراسلات الواردة';

    /**
     * The folder structure replies are kept in, inside a matter's folder, as it is
     * set — a path whose parts may hold placeholders — empty when they aren't kept.
     */
    public static function receivedEmailsFolder(): string
    {
        return trim((string) Setting::get(self::RECEIVED_EMAILS, self::DEFAULT_RECEIVED_EMAILS));
    }

    /**
     * The folder structure for this matter, inside its OneDrive folder: the setting, with
     * its placeholders filled in from the matter (see resolvePath()) — the one of the side
     * the email came from, when it has one of its own (ReceivedEmailFiling).
     */
    public static function receivedEmailsFolderFor(Matter $matter, ?CarbonInterface $at = null, string $side = ReceivedEmailFiling::GENERAL, string $from = '', bool $nextSerial = true): string
    {
        $template = ReceivedEmailFiling::folderTemplate($matter, $side);

        if ($template === '') {
            return '';
        }

        // A creditor's: {{creditor}}, its name, by the address it came from. {{serial}}: the next number under the
        // folder — or, to compare with where an email is, no folder for it at all.
        $path = self::resolvePath($template, $matter, $at ?? now(), extra: [...ReceivedEmailFiling::creditorValues($matter, $side, $from), self::SERIAL => $nextSerial ? self::SERIAL_MARK : '']);

        return self::withSerial($matter, $path);
    }

    /**
     * Where an email's files are in the matter's OneDrive folder: where they were put (or moved to), else — one
     * kept before that was noted — the sent-emails folder for one sent, the received-emails one for one come in.
     */
    public static function emailFolder(MatterEmail $email, Matter $matter): string
    {
        $kept = (string) (((array) $email->onedrive)['folder'] ?? '');

        if ($kept !== '') {
            return $kept;
        }

        return $email->direction === MatterEmail::SENT
            ? self::sentEmailsFolderFor($matter, $email->at)
            : self::receivedEmailsFolderFor($matter, $email->at);
    }

    /**
     * The structure as it is set for this matter: its type's own, else the default one
     * (Settings → OneDrive Folders). Empty: received emails are not saved in OneDrive.
     */
    public static function receivedEmailsTemplate(?Matter $matter = null): string
    {
        return self::templateOf($matter, 'onedrive_received_emails', self::receivedEmailsFolder());
    }

    /** The folder for sent emails as it is set for this matter: its type's own, else the default one. Empty: not saved in OneDrive. */
    public static function sentEmailsTemplate(?Matter $matter = null): string
    {
        return self::templateOf($matter, 'onedrive_sent_emails', self::sentEmailsFolder());
    }

    /** The folder for the emails sent to a creditor, as set for this matter: its type's own, else the setting; empty: the sent emails folder. */
    public static function sentToCreditorsTemplate(?Matter $matter = null): string
    {
        return self::templateOf($matter, 'onedrive_sent_creditors', trim((string) Setting::get(self::SENT_TO_CREDITORS, '')));
    }

    /** The folder for signed minutes as it is set for this matter: its type's own, else the default one. */
    public static function signedMinutesTemplate(?Matter $matter = null): string
    {
        return self::templateOf($matter, 'onedrive_signed_minutes', self::signedMinutesFolder());
    }

    /** The matter type's own setting (a column of the type), when it has one; else the default. */
    private static function templateOf(?Matter $matter, string $column, string $default): string
    {
        $own = $matter ? trim((string) $matter->loadMissing('type')->type?->getAttribute($column)) : '';

        return $own !== '' ? $own : $default;
    }

    /**
     * A folder path whose parts may hold the matter's placeholders — the ones letters
     * use: "{{matter.custom.phase}}/01 system + 02/{{matter.plaintiff_capacity}}" for
     * "Execution/01 system + 02/المدعي". Each part is made on its own, so a value with a
     * "/" in it ("2026/123") does not make folders; a part that comes out empty (a custom
     * field the matter has no value for) is left out, and so is a placeholder that is not
     * known. When nothing is left, the default folder.
     *
     * @param  array<string, string>  $extra  placeholders of its own: a creditor's {{creditor}}, a report's {{number}}
     */
    public static function resolvePath(string $template, Matter $matter, ?CarbonInterface $at = null, string $fallback = self::DEFAULT_RECEIVED_EMAILS, array $extra = []): string
    {
        if (! str_contains($template, '{{')) {
            return $template;
        }

        // For an email that came in, the day it reached us too: {{day}} (2026-10-16),
        // {{month}} (2026-10) and {{year}}.
        $dates = [];
        if ($at !== null) {
            $at = Carbon::instance($at)->setTimezone(config('app.timezone'));
            $dates = ['day' => $at->format('Y-m-d'), 'month' => $at->format('Y-m'), 'year' => $at->format('Y')];
        }

        $parts = self::resolveParts($template, $matter, [...BulkMailPlaceholders::forMatter($matter), ...$dates, ...$extra]);

        return $parts !== [] ? implode('/', $parts) : $fallback;
    }

    /**
     * The placeholders of a path that the matter has no value for — a custom field it has none
     * of, one that does not exist: the folder each makes is left out of the path.
     *
     * @return list<string> as written, e.g. "{{matter.custom.Phase}}"
     */
    public static function emptyPlaceholders(string $template, Matter $matter, ?CarbonInterface $at = null): array
    {
        if (! preg_match_all('/\{\{\s*[^{}]+?\s*\}\}/u', $template, $found)) {
            return [];
        }

        $values = BulkMailPlaceholders::forMatter($matter);
        if ($at !== null) {
            $at = Carbon::instance($at)->setTimezone(config('app.timezone'));
            $values = [...$values, 'day' => $at->format('Y-m-d'), 'month' => $at->format('Y-m'), 'year' => $at->format('Y')];
        }

        return collect($found[0])->unique()->filter(
            fn (string $tag): bool => trim((string) preg_replace('/\{\{[^{}]*\}\}/u', '', BulkMailPlaceholders::apply($tag, $values))) === '',
        )->values()->all();
    }

    /**
     * The folders of a path, one by one, with the matter's placeholders filled in (see
     * resolvePath()) — those that come out empty left out.
     *
     * @param  array<string, string>  $values  the matter's placeholders (BulkMailPlaceholders::forMatter())
     * @return list<string>
     */
    private static function resolveParts(string $template, Matter $matter, array $values): array
    {
        $parts = [];

        foreach (OneDriveClient::segments($template) as $segment) {
            $resolved = (string) preg_replace('/\{\{[^{}]*\}\}/u', '', BulkMailPlaceholders::apply($segment, $values));
            $resolved = self::clean($resolved);

            if ($resolved !== '') {
                $parts[] = $resolved;
            }
        }

        return $parts;
    }

    /**
     * The standard subfolders of this matter's folder: the list of its type (else the
     * default one), each line with the matter's placeholders filled in —
     * "{{matter.custom.phase}}/01 المراسلات". A line that comes out empty is left out.
     *
     * @return list<string>
     */
    public static function subfoldersFor(Matter $matter): array
    {
        $lines = self::subfolders($matter);

        // Read once, and only when some line asks for them.
        $values = null;
        $resolved = [];

        foreach ($lines as $line) {
            if (! str_contains($line, '{{')) {
                $resolved[] = $line;

                continue;
            }

            $values ??= BulkMailPlaceholders::forMatter($matter);
            $path = implode('/', self::resolveParts($line, $matter, $values));

            if ($path !== '') {
                $resolved[] = $path;
            }
        }

        return array_values(array_unique($resolved));
    }

    /** Where sent emails go when the setting has not been changed. */
    public const DEFAULT_SENT_EMAILS = 'المراسلات الصادرة';

    /** Where signed minutes go when the setting has not been changed. */
    public const DEFAULT_SIGNED_MINUTES = 'محاضر موقعة';

    /**
     * The folder sent emails are saved in, for this matter: the setting with the matter's
     * placeholders filled in, and {{day}} (…) as the day it is sent — see resolvePath().
     * Empty when they are not saved in OneDrive.
     */
    /**
     * @param  list<string>  $to  whom it went to: for {{creditor}} in the folder, the creditor one of them is (a claim's
     *                            correspondence in its own folder); no creditor among them, the folder is left out
     */
    public static function sentEmailsFolderFor(Matter $matter, ?CarbonInterface $at = null, array $to = [], bool $nextSerial = true): string
    {
        // To a creditor: its own folder, when one is set (a claim's correspondence with the claim).
        $creditor = self::sentCreditorValues($matter, $to);
        $template = ($creditor !== [] ? self::sentToCreditorsTemplate($matter) : '') ?: self::sentEmailsTemplate($matter);

        if ($template === '') {
            return '';
        }

        $path = self::resolvePath($template, $matter, $at ?? now(), self::DEFAULT_SENT_EMAILS, [...$creditor, self::SERIAL => $nextSerial ? self::SERIAL_MARK : '']);

        return self::withSerial($matter, $path);
    }

    /**
     * For an email sent to a creditor (or its agent): {{creditor}} and {{creditor.number}} — the first of those it
     * went to that is a creditor's address on the matter.
     *
     * @param  list<string>  $to
     * @return array<string, string>
     */
    private static function sentCreditorValues(Matter $matter, array $to): array
    {
        foreach ($to as $address) {
            $values = ReceivedEmailFiling::creditorValues($matter, ReceivedEmailFiling::CREDITOR, (string) $address);

            if ($values !== []) {
                return $values;
            }
        }

        return [];
    }

    /**
     * A path with {{serial}} in it: the number of the next email filed under the same folder — the emails of a day
     * numbered 1, 2, 3 — by the folders the matter's emails were filed in so far. Without it, the path as it is.
     */
    private static function withSerial(Matter $matter, string $path): string
    {
        if (! str_contains($path, self::SERIAL_MARK)) {
            return $path;
        }

        $segments = OneDriveClient::segments($path);
        $depth = (int) array_key_first(array_filter($segments, fn (string $segment): bool => str_contains($segment, self::SERIAL_MARK)));
        $prefix = implode('/', array_slice($segments, 0, $depth));
        [$before, $after] = explode(self::SERIAL_MARK, $segments[$depth], 2);
        $pattern = '/^'.preg_quote($before, '/').'(\d+)'.preg_quote($after, '/').'$/u';

        $used = MatterEmail::query()
            ->where('matter_id', $matter->getKey())
            ->whereNotNull('onedrive->folder')
            ->when($prefix !== '', fn ($query) => $query->where('onedrive->folder', 'like', addcslashes($prefix, '%_\\').'/%'))
            ->pluck('onedrive')
            ->map(fn ($onedrive): string => (string) (((array) $onedrive)['folder'] ?? ''))
            ->filter(fn (string $folder): bool => $prefix === '' || str_starts_with(mb_strtolower($folder), mb_strtolower($prefix).'/'))
            ->map(fn (string $folder): int => preg_match($pattern, OneDriveClient::segments($folder)[$depth] ?? '', $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        $segments[$depth] = $before.($used + 1).$after;

        return implode('/', $segments);
    }

    /** The folder signed minutes are saved in, for this matter — the setting with the matter's placeholders filled in. */
    public static function signedMinutesFolderFor(Matter $matter): string
    {
        return self::resolvePath(self::signedMinutesTemplate($matter), $matter, now(), self::DEFAULT_SIGNED_MINUTES);
    }

    /** The subfolder sent emails are kept in — empty when they aren't. */
    public static function sentEmailsFolder(): string
    {
        return trim((string) Setting::get(self::SENT_EMAILS, 'المراسلات الصادرة'));
    }

    /**
     * A signed copy of minutes, into the matter's folder in its assistant's
     * OneDrive — in the signed-minutes subfolder (made when missing). Null
     * when the matter has no folder.
     */
    public function uploadSignedMinutes(Matter $matter, string $name, string $contents, string $mime): ?string
    {
        return $this->uploadToSubfolder($matter, self::signedMinutesFolderFor($matter), $name, $contents, $mime);
    }

    /**
     * The matter's folder in an assistant's OneDrive (made, with the assistant's
     * OneDrive account on their profile) — null when the matter has none.
     */
    public static function folderFor(Matter $matter): ?MatterOneDriveFolder
    {
        return MatterOneDriveFolder::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', MatterOneDriveFolder::CREATED)
            ->whereNotNull('drive_item_id')
            ->with('party')
            ->oldest('id')
            ->get()
            ->first(fn (MatterOneDriveFolder $f) => filled($f->party?->onedrive_email));
    }

    /**
     * Whether the received emails of this matter are meant to go to OneDrive
     * (the feature is on, with a folder for them) but the matter has no
     * folder there — they are kept in the system only.
     */
    public static function receivedEmailsNotInOneDrive(Matter $matter): bool
    {
        return self::enabled() && self::receivedEmailsTemplate($matter) !== '' && self::folderFor($matter) === null;
    }

    /**
     * A file into a subfolder of the matter's folder in its assistant's
     * OneDrive (made when missing) — "a/b" for one inside another. Its
     * link; null when the matter has no folder.
     */
    public function uploadToSubfolder(Matter $matter, string $subfolder, string $name, string $contents, string $mime, bool $replace = false): ?string
    {
        $folder = self::folderFor($matter);

        if (! $folder) {
            return null;
        }

        $user = (string) $folder->party->onedrive_email;
        $target = $this->ensurePath($user, $folder, $subfolder);

        // A name with no extension stays without one: "name." is refused ("illegal characters").
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $file = (self::clean(pathinfo($name, PATHINFO_FILENAME)) ?: 'file').($extension !== '' ? '.'.$extension : '');

        return $this->client->upload($user, $target, $file, $contents, $mime, $replace)['webUrl'];
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

    // ── Folders renamed

    /** A path within a matter's folder as OneDrive has it: each folder's name cleaned, joined with "/". */
    public static function cleanPath(string $path): string
    {
        return implode('/', array_filter(array_map([self::class, 'clean'], OneDriveClient::segments($path)), fn (string $segment): bool => $segment !== ''));
    }

    /**
     * Every line of a type's folder structure, as written: its standard subfolders (its own, else the default) and
     * each phase's own folders under the phase's folder — to say which folders were renamed (RenameTypeFolders).
     *
     * @return list<string>
     */
    public static function structureLines(Type $type): array
    {
        $lines = self::subfolders($type);

        foreach ($type->phaseNames() as $phase) {
            foreach ($type->phaseFolders($phase) as $line) {
                $lines[] = self::PHASE.'/'.$line;
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * A folder of the matter's folder renamed — or moved, when its new path is under another folder — in each of
     * the matter's OneDrive folders (one an assistant's), what is in it with it, and every path the system keeps
     * under it changed to the new (repathKept()). One of the new name there already: what was in the old goes into
     * it, and the emptied old folder is removed. Paths within the matter's folder.
     *
     * @return array{folders: int, emails: int, files: int, reports: int, links: int}
     */
    public function renameWithin(Matter $matter, string $from, string $to): array
    {
        $from = self::cleanPath($from);
        $to = self::cleanPath($to);
        $done = ['folders' => 0, 'emails' => 0, 'files' => 0, 'reports' => 0, 'links' => 0];

        if ($from === '' || $to === '' || mb_strtolower($from) === mb_strtolower($to)) {
            return $done;
        }

        $folders = MatterOneDriveFolder::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', MatterOneDriveFolder::CREATED)
            ->whereNotNull('drive_item_id')
            ->with('party')
            ->get()
            ->filter(fn (MatterOneDriveFolder $folder): bool => filled($folder->party?->onedrive_email));

        foreach ($folders as $folder) {
            $done['folders'] += $this->renameIn((string) $folder->party->onedrive_email, (string) $folder->drive_item_id, $from, $to) ? 1 : 0;
        }

        return [...$done, ...$this->repathKept($matter, $from, $to)];
    }

    /**
     * In one OneDrive: the folder at a path under the matter's folder renamed, or moved, to another path. Whether
     * it was there to rename.
     */
    private function renameIn(string $user, string $rootId, string $from, string $to): bool
    {
        $fromId = $this->walk($user, $rootId, $from);

        if ($fromId === null) {
            return false;
        }

        $segments = OneDriveClient::segments($to);
        $leaf = (string) array_pop($segments);
        $parentPath = implode('/', $segments);
        $parentId = $parentPath === '' ? $rootId : (string) $this->client->ensureFolder($user, $parentPath, $rootId)['id'];

        // One of the new name there already (made by the structure since): what was in the old goes into it.
        $existing = collect($this->client->childFolders($user, $parentId))->first(fn (array $f): bool => mb_strtolower($f['name']) === mb_strtolower($leaf));
        if ($existing !== null && (string) $existing['id'] !== $fromId) {
            $this->mergeInto($user, $fromId, (string) $existing['id']);

            return true;
        }

        $fromSegments = OneDriveClient::segments($from);
        $fromLeaf = (string) array_pop($fromSegments);
        $fromParentPath = implode('/', $fromSegments);
        $fromParentId = $fromParentPath === '' ? $rootId : $this->walk($user, $rootId, $fromParentPath);

        if ($fromParentId !== $parentId) {
            $this->client->move($user, $fromId, $parentId);
        }

        if ($fromLeaf !== $leaf) {
            $this->client->rename($user, $fromId, $leaf);
        }

        return true;
    }

    /**
     * Every path the system keeps under a folder of the matter's, changed to the folder's new path: the emails'
     * folders, the files read from OneDrive, the periodic reports' folders, and the links of signed minutes and
     * attachments that hold the path. How many of each.
     *
     * @return array{emails: int, files: int, reports: int, links: int}
     */
    private function repathKept(Matter $matter, string $from, string $to): array
    {
        $lower = mb_strtolower($from);
        $under = fn (string $path): bool => mb_strtolower($path) === $lower || str_starts_with(mb_strtolower($path), $lower.'/');
        $moved = fn (string $path): string => $to.mb_substr($path, mb_strlen($from));
        $like = addcslashes($from, '%_\\').'%';
        $done = ['emails' => 0, 'files' => 0, 'reports' => 0, 'links' => 0];

        foreach (MatterEmail::query()->where('matter_id', $matter->getKey())->where('onedrive->folder', 'like', $like)->get() as $email) {
            $folder = (string) (((array) $email->onedrive)['folder'] ?? '');

            if ($under($folder)) {
                $email->update(['onedrive' => [...(array) $email->onedrive, 'folder' => $moved($folder)]]);
                $done['emails']++;
            }
        }

        foreach (MatterEmail::query()->where('matter_id', $matter->getKey())->where('onedrive->attachments_folder', 'like', $like)->get() as $email) {
            $apart = (string) (((array) $email->onedrive)['attachments_folder'] ?? '');

            if ($under($apart)) {
                $email->update(['onedrive' => [...(array) $email->onedrive, 'attachments_folder' => $moved($apart)]]);
                $done['emails']++;
            }
        }

        foreach (MatterOneDriveFile::query()->where('matter_id', $matter->getKey())->where('path', 'like', $like)->get() as $file) {
            if ($under((string) $file->path)) {
                $file->update(['path' => $moved((string) $file->path)]);
                $done['files']++;
            }
        }

        foreach ($matter->periodicReports()->where('folder', 'like', $like)->get() as $report) {
            if ($under((string) $report->folder)) {
                $report->update(['folder' => $moved((string) $report->folder)]);
                $done['reports']++;
            }
        }

        foreach ($matter->attachments()->whereNotNull('onedrive_url')->get(['id', 'onedrive_url']) as $attachment) {
            if (($url = self::relinkUrl((string) $attachment->onedrive_url, $from, $to)) !== $attachment->onedrive_url) {
                $attachment->update(['onedrive_url' => $url]);
                $done['links']++;
            }
        }

        foreach (MinutesDelivery::query()->whereIn('matter_minutes_id', $matter->minutes()->select('id'))->whereNotNull('onedrive_url')->get(['id', 'onedrive_url']) as $delivery) {
            if (($url = self::relinkUrl((string) $delivery->onedrive_url, $from, $to)) !== $delivery->onedrive_url) {
                $delivery->update(['onedrive_url' => $url]);
                $done['links']++;
            }
        }

        return $done;
    }

    /**
     * A link to a file in OneDrive, with a folder of its path renamed: the folder as OneDrive writes it in a web
     * address (each name encoded), and as written plainly, replaced by the new — the link as it was when the
     * folder is not in it.
     */
    public static function relinkUrl(string $url, string $from, string $to): string
    {
        $encode = fn (string $path): string => implode('/', array_map('rawurlencode', OneDriveClient::segments($path)));

        foreach ([[$encode($from), $encode($to)], [$from, $to]] as [$old, $new]) {
            $url = str_replace('/'.$old.'/', '/'.$new.'/', $url);

            if (str_ends_with($url, '/'.$old)) {
                $url = substr($url, 0, -strlen('/'.$old)).'/'.$new;
            }
        }

        return $url;
    }

    // ── The two folders at the top of each assistant's OneDrive

    /**
     * The standard names of the folders at the top of each assistant's OneDrive: the matters in progress and the
     * finished (Settings → OneDrive Folders) — the usual Arabic names until set.
     *
     * @return array{matters: string, finished: string}
     */
    public static function standardFolders(): array
    {
        return [
            'matters' => trim((string) Setting::get(self::MATTERS_FOLDER, '')) ?: 'القضايا الجارية',
            'finished' => trim((string) Setting::get(self::FINISHED_FOLDER, '')) ?: 'القضايا المنتهية',
        ];
    }

    /**
     * An assistant's two folders given the standard names: what each holds moved under the standard name at the
     * top of their OneDrive (into a folder of that name there already, when there is one), the paths on their
     * profile set to the names, their matters' links refreshed and the files' links that held the old path changed.
     * What was done: each folder's old and new name, when it was changed, and how many links.
     *
     * @return array{matters: ?string, finished: ?string, links: int}
     */
    public function standardiseRoots(Party $party): array
    {
        $user = (string) $party->onedrive_email;
        $standard = self::standardFolders();
        $done = ['matters' => null, 'finished' => null, 'links' => 0];
        $changed = false;

        foreach (['matters' => 'onedrive_path', 'finished' => 'onedrive_finished_path'] as $which => $column) {
            $new = $standard[$which];
            $old = trim((string) $party->{$column});

            if (mb_strtolower(self::cleanPath($old)) === mb_strtolower(self::cleanPath($new))) {
                continue;
            }

            $target = $this->client->ensureFolder($user, $new);

            if ($old !== '') {
                // The whole folder's contents go under the standard name.
                $current = $this->client->findFolder($user, $old);
                if ($current !== null && $current['id'] !== $target['id']) {
                    $this->mergeInto($user, $current['id'], $target['id']);
                }
                $done['links'] += $this->relinkAll($party, $old, $new);
            } else {
                // At the top of their OneDrive: only the matters' folders go, not everything there.
                $rows = $party->matterOneDriveFolders()->where('status', MatterOneDriveFolder::CREATED)->whereNotNull('drive_item_id')->when($which === 'matters', fn ($q) => $q->whereNull('moved_at'), fn ($q) => $q->whereNotNull('moved_at'))->get();
                foreach ($rows->unique('drive_item_id') as $row) {
                    $this->client->move($user, (string) $row->drive_item_id, $target['id']);
                }
            }

            $party->forceFill([$column => $new])->save();
            $done[$which] = ($old !== '' ? $old : __('(the top of their OneDrive)')).' → '.$new;
            $changed = true;
        }

        // Their matters' links: where each folder is now.
        if ($changed) {
            foreach ($party->matterOneDriveFolders()->where('status', MatterOneDriveFolder::CREATED)->whereNotNull('drive_item_id')->get() as $row) {
                try {
                    $row->update(['web_url' => $this->client->itemInfo($user, (string) $row->drive_item_id)['webUrl'] ?: $row->web_url]);
                } catch (Throwable $e) {
                    Log::info('OneDrive link not refreshed: '.$e->getMessage(), ['folder' => $row->getKey()]);
                }
            }
        }

        return $done;
    }

    /**
     * The links of the files of this assistant's matters that hold a path renamed (the folder their matters are
     * in): changed to the new. How many.
     */
    private function relinkAll(Party $party, string $from, string $to): int
    {
        $matters = $party->matterOneDriveFolders()->pluck('matter_id')->unique()->values()->all();
        $links = 0;

        if ($matters === []) {
            return 0;
        }

        foreach (Attachment::query()->whereIn('matter_id', $matters)->whereNotNull('onedrive_url')->get(['id', 'onedrive_url']) as $attachment) {
            if (($url = self::relinkUrl((string) $attachment->onedrive_url, $from, $to)) !== $attachment->onedrive_url) {
                $attachment->update(['onedrive_url' => $url]);
                $links++;
            }
        }

        foreach (MinutesDelivery::query()->whereIn('matter_minutes_id', MatterMinutes::query()->whereIn('matter_id', $matters)->select('id'))->whereNotNull('onedrive_url')->get(['id', 'onedrive_url']) as $delivery) {
            if (($url = self::relinkUrl((string) $delivery->onedrive_url, $from, $to)) !== $delivery->onedrive_url) {
                $delivery->update(['onedrive_url' => $url]);
                $links++;
            }
        }

        return $links;
    }

    /**
     * Where the files an email came with go, apart from its PDF and original: the attachments folder set for its
     * sender's side (ReceivedEmailFiling::attachmentsTemplate()), with the matter's placeholders, {{side}} (the
     * side's name on the type), {{party}} (who sent it), {{day}} and a creditor's {{creditor}} filled in. Empty:
     * beside the PDF, in a folder of its name.
     */
    public static function attachmentsFolderFor(Matter $matter, ?CarbonInterface $at, string $side, string $from, string $party = ''): string
    {
        $template = ReceivedEmailFiling::attachmentsTemplate($matter, $side);

        if ($template === '') {
            return '';
        }

        $matter->loadMissing('type');

        return self::resolvePath($template, $matter, $at ?? now(), '', [
            'side' => $side === ReceivedEmailFiling::GENERAL ? '' : Type::sideLabel($matter->type, $side),
            'party' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', $party)), SentEmailArchive::PARTY_LIMIT, '…'),
            ...ReceivedEmailFiling::creditorValues($matter, $side, $from),
        ]);
    }
}
