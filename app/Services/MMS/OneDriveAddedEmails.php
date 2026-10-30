<?php

namespace App\Services\MMS;

use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFile;
use App\Models\MatterOneDriveFolder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emails put in a matter's OneDrive folder by hand — dragged out of Outlook (.msg) or another mail program (.eml),
 * anywhere in it — read and added to the matter: to its emails, its progress and its contacts; its PDF and the
 * files it came with beside the file, which stays where it was put; its sender a creditor when it was put in the
 * creditors' folder. Each file is looked at once (MatterOneDriveFile); the emails the system itself saved there
 * are known by their names and never downloaded; one the matter already has (its Message-ID) is not added twice.
 */
class OneDriveAddedEmails
{
    /** How deep in a matter's folder files are looked for. */
    private const DEPTH = 8;

    /** A file bigger than this is not read. */
    private const MAX_BYTES = 40 * 1024 * 1024;

    /** Folders asked of OneDrive at once. */
    private const AT_ONCE = 20;

    /** A creditors' folder's placeholders that are an email's, not the matter's: where the folders of its own begin. */
    private const EMAIL_PLACEHOLDERS = '/\{\{\s*(creditor|side|day|month|year|date|time|kind|party|subject)\s*\}\}/iu';

    public function __construct(
        private readonly OneDriveClient $client,
        private readonly MatterReplyCollector $collector,
        private readonly MatterCreditors $creditors,
    ) {}

    /**
     * The folders of the matters in progress, the one read longest ago first, until the time is up — at least one.
     *
     * @return array{folders: int, added: int, errors: list<string>, stopped: bool}
     */
    public function readDue(?int $maxSeconds = null): array
    {
        $deadline = $maxSeconds !== null ? microtime(true) + $maxSeconds : null;
        $result = ['folders' => 0, 'added' => 0, 'errors' => [], 'stopped' => false];

        if (! MatterOneDriveFolders::enabled() || ! $this->client->isConfigured()) {
            return $result;
        }

        foreach ($this->folders() as $group) {
            if ($deadline !== null && $result['folders'] > 0 && microtime(true) >= $deadline) {
                $result['stopped'] = true;

                break;
            }

            $result['added'] += $this->readGroup($group, null, $deadline, $result['errors']);
            $result['folders']++;
        }

        return $result;
    }

    /**
     * One matter's folder, now — asked for on the matter: how many emails were added, and why any could not be.
     *
     * @return array{added: int, errors: list<string>}
     */
    public function readMatter(Matter $matter, ?int $userId = null): array
    {
        $folder = MatterOneDriveFolders::folderFor($matter);
        $errors = [];

        if ($folder === null) {
            return ['added' => 0, 'errors' => [__('The matter has no OneDrive folder.')]];
        }

        // With the matters it is shared with (another phase of the case) — this one among them, finished or not.
        $group = $this->folders((string) $folder->drive_item_id)->first() ?? collect();
        $group = $group->contains('matter_id', $matter->getKey()) ? $group : $group->push($folder->setRelation('matter', $matter));

        return ['added' => $this->readGroup($group, $userId, null, $errors), 'errors' => $errors];
    }

    /**
     * The matters' folders to read, by the OneDrive folder they are (a main folder may be shared by the phases of a
     * case), the one read longest ago first: each matter's own — the one its emails are saved in (folderFor()).
     *
     * @return Collection<int, Collection<int, MatterOneDriveFolder>>
     */
    private function folders(?string $driveItemId = null): Collection
    {
        return MatterOneDriveFolder::query()
            ->where('status', MatterOneDriveFolder::CREATED)
            ->whereNotNull('drive_item_id')
            ->when(
                $driveItemId,
                fn ($query) => $query->where('drive_item_id', $driveItemId),
                fn ($query) => $query->whereNull('moved_at')->whereHas('matter', fn ($matter) => $matter->whereNull('final_report_at')),
            )
            ->whereHas('party', fn ($party) => $party->whereNotNull('onedrive_email')->where('onedrive_email', '!=', ''))
            ->with(['matter.type', 'party'])
            ->orderBy('id')
            ->get()
            ->filter(fn (MatterOneDriveFolder $folder): bool => $folder->matter !== null)
            ->unique('matter_id')
            ->groupBy('drive_item_id')
            ->sortBy(fn (Collection $group): int => (int) $group->map(fn (MatterOneDriveFolder $folder): int => (int) $folder->emails_read_at?->timestamp)->min())
            ->values();
    }

    /**
     * @param  Collection<int, MatterOneDriveFolder>  $group  the matters whose folder it is
     * @param  list<string>  $errors
     */
    private function readGroup(Collection $group, ?int $userId, ?float $deadline, array &$errors): int
    {
        $first = $group->first();
        $user = (string) $first->party->onedrive_email;
        $matters = $group->map(fn (MatterOneDriveFolder $folder): Matter => $folder->matter)->values();
        $added = 0;

        try {
            [$skipFiles, $skipFolders] = $this->savedHere($matters);
            $files = $this->emailFiles($user, (string) $first->drive_item_id, $skipFiles, $skipFolders);
            $seen = MatterOneDriveFile::query()->whereIn('item_id', array_column($files, 'id') ?: [''])->get()->keyBy('item_id');

            foreach ($files as $file) {
                $row = $seen->get($file['id']);

                if ($row !== null && ($row->outcome !== MatterOneDriveFile::FAILED || $row->attempts >= MatterOneDriveFile::TRIES)) {
                    continue;
                }

                if ($deadline !== null && microtime(true) >= $deadline) {
                    break;
                }

                $added += $this->readFile($this->matterOf($matters, $file['path']), $user, $file, $row, $userId) ? 1 : 0;
            }
        } catch (Throwable $e) {
            $errors[] = $first->folder_name.': '.$e->getMessage();
            Log::warning('Emails added in OneDrive not read: '.$e->getMessage(), ['folder' => $first->getKey()]);
        }

        MatterOneDriveFolder::query()->whereKey($group->pluck('id')->all())->update(['emails_read_at' => now()]);

        return $added;
    }

    /**
     * What the system itself saved in the matters' folder — never to be read as added: each email's original
     * (.eml) and the one put there by hand, by their paths, and the folders of the files they came with.
     *
     * @param  Collection<int, Matter>  $matters
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function savedHere(Collection $matters): array
    {
        $files = [];
        $folders = [];
        $byId = $matters->keyBy('id');

        MatterEmail::query()
            ->whereIn('matter_id', $byId->keys())
            ->whereNotNull('files')
            ->get(['id', 'matter_id', 'direction', 'files', 'onedrive'])
            ->each(function (MatterEmail $email) use ($byId, &$files, &$folders): void {
                $base = pathinfo((string) ($email->files[0]['name'] ?? ''), PATHINFO_FILENAME);

                if ($base === '') {
                    return;
                }

                $folder = self::normal(MatterOneDriveFolders::emailFolder($email, $byId[$email->matter_id]));
                $at = ($folder !== '' ? $folder.'/' : '').self::normal($base);
                $files[$at.'.eml'] = true;
                $files[$at.'.msg'] = true;
                $folders[$at] = true;

                if (filled($added = ((array) $email->onedrive)['added'] ?? null)) {
                    $files[($folder !== '' ? $folder.'/' : '').self::normal((string) $added)] = true;
                }
            });

        return [$files, $folders];
    }

    /**
     * The email files anywhere in the folder, a level at a time — not those saved there by the system.
     *
     * @param  array<string, true>  $skipFiles
     * @param  array<string, true>  $skipFolders
     * @return list<array{id: string, name: string, size: ?int, path: string, folder: string}>
     */
    private function emailFiles(string $user, string $rootId, array $skipFiles, array $skipFolders): array
    {
        $level = [['id' => $rootId, 'path' => '']];
        $found = [];

        for ($depth = 0; $level !== [] && $depth < self::DEPTH; $depth++) {
            $next = [];

            foreach (array_chunk($level, self::AT_ONCE) as $chunk) {
                $listings = $this->client->childrenOfMany($user, array_column($chunk, 'id'));

                foreach ($chunk as $parent) {
                    foreach ($listings[$parent['id']] ?? [] as $item) {
                        $path = ltrim($parent['path'].'/'.$item['name'], '/');

                        if ($item['folder']) {
                            if ((int) $item['children'] > 0 && ! isset($skipFolders[self::normal($path)])) {
                                $next[] = ['id' => $item['id'], 'path' => $path];
                            }
                        } elseif (EmailFile::isEmail($item['name']) && ! isset($skipFiles[self::normal($path)])) {
                            $found[] = ['id' => $item['id'], 'name' => $item['name'], 'size' => $item['size'], 'path' => $path, 'folder' => $parent['path']];
                        }
                    }
                }
            }

            $level = $next;
        }

        return $found;
    }

    /**
     * Whose a file in a shared main folder is: the matter whose phase folder it is in, else the newest still in
     * progress.
     *
     * @param  Collection<int, Matter>  $matters
     */
    private function matterOf(Collection $matters, string $path): Matter
    {
        if ($matters->count() === 1) {
            return $matters->first();
        }

        $top = self::normal(OneDriveClient::segments($path)[0] ?? '');
        $byPhase = $matters->first(fn (Matter $matter): bool => in_array($top, array_map([self::class, 'normal'], MatterOneDriveFolders::phaseFolders($matter)), true));

        return $byPhase ?? $matters->whereNull('final_report_at')->sortByDesc('id')->first() ?? $matters->sortByDesc('id')->first();
    }

    /**
     * One file: read, and added to the matter unless it has it. Whether it was added.
     *
     * @param  array{id: string, name: string, size: ?int, path: string, folder: string}  $file
     */
    private function readFile(Matter $matter, string $user, array $file, ?MatterOneDriveFile $row, ?int $userId): bool
    {
        $note = fn (string $outcome, ?int $emailId = null, ?string $error = null) => MatterOneDriveFile::query()->updateOrCreate(
            ['item_id' => $file['id']],
            [
                'matter_id' => $matter->getKey(),
                'path' => mb_substr($file['path'], 0, 1000),
                'outcome' => $outcome,
                'matter_email_id' => $emailId,
                'attempts' => (int) $row?->attempts + 1,
                'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
            ],
        );

        if ((int) $file['size'] > self::MAX_BYTES) {
            $note(MatterOneDriveFile::UNREADABLE, null, __('Too big to read.'));

            return false;
        }

        try {
            $info = $this->client->itemInfo($user, $file['id']);
            $message = filled($info['downloadUrl']) ? EmailFile::read($file['name'], $this->client->download((string) $info['downloadUrl'])) : null;

            if ($message === null || (blank($message['from'] ?? null) && blank($message['subject'] ?? null))) {
                $note(MatterOneDriveFile::UNREADABLE, null, __('Not an email that can be read.'));

                return false;
            }

            $id = MatterEmail::messageId($message['message_id'] ?? null) ?? 'onedrive.'.$file['id'];

            if (($have = $matter->emails()->where('message_id', $id)->value('id')) !== null) {
                $note(MatterOneDriveFile::KNOWN, (int) $have);

                return false;
            }

            $email = $this->collector->keepAdded($matter, $message, $id, $file['folder'], $file['name'], $userId);
            $this->creditor($matter, $email, $file['folder']);
            $note(MatterOneDriveFile::ADDED, (int) $email->getKey());

            return true;
        } catch (Throwable $e) {
            Log::warning('Email added in OneDrive not read: '.$e->getMessage(), ['matter' => $matter->getKey(), 'file' => $file['path']]);
            $note(MatterOneDriveFile::FAILED, null, $e->getMessage());

            return false;
        }
    }

    /**
     * Put in the matter's creditors' folder: its sender a creditor — of the name of the creditor's folder it is in
     * ({{creditor}}), else of the sender's own name; an address already a creditor's left with it.
     */
    private function creditor(Matter $matter, MatterEmail $email, string $folder): void
    {
        if ($email->direction !== MatterEmail::RECEIVED || ! MatterCreditors::kept($matter)) {
            return;
        }

        $name = self::creditorFolderName($matter, $folder);

        if ($name === null) {
            return;
        }

        [$sender, $address] = MatterContact::split((string) $email->from);
        $this->creditors->merge($matter, $name !== '' ? $name : $sender, array_filter([$address]));
    }

    /**
     * The creditor's name a folder of the matter's says — the folder at {{creditor}} of the creditors' folder
     * structure; '' when it is in the creditors' folder but none of a creditor's; null when it is not in it.
     */
    public static function creditorFolderName(Matter $matter, string $folder): ?string
    {
        $template = ReceivedEmailFiling::folderTemplate($matter, ReceivedEmailFiling::CREDITOR);

        // None of their own: the creditors' emails are saved with everyone's.
        if (trim($template) === '' || $template === ReceivedEmailFiling::folderTemplate($matter, ReceivedEmailFiling::GENERAL)) {
            return null;
        }

        $segments = OneDriveClient::segments($template);
        $fixed = [];

        foreach ($segments as $segment) {
            if (preg_match(self::EMAIL_PLACEHOLDERS, $segment)) {
                break;
            }

            $fixed[] = $segment;
        }

        $base = $fixed !== [] ? array_map([self::class, 'normal'], OneDriveClient::segments(MatterOneDriveFolders::resolvePath(implode('/', $fixed), $matter, null, ''))) : [];
        $parts = OneDriveClient::segments($folder);

        if ($base === [] || array_map([self::class, 'normal'], array_slice($parts, 0, count($base))) !== $base) {
            return null;
        }

        $next = $segments[count($fixed)] ?? '';
        $inside = $parts[count($base)] ?? null;

        return $inside !== null && preg_match('/\{\{\s*creditor\s*\}\}/iu', $next) ? trim($inside) : '';
    }

    /** A path as OneDrive keeps it, to compare: each folder as it is named there, whatever its case. */
    private static function normal(string $path): string
    {
        return mb_strtolower(implode('/', array_map([MatterOneDriveFolders::class, 'clean'], OneDriveClient::segments($path))));
    }
}
