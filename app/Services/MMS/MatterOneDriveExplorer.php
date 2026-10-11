<?php

namespace App\Services\MMS;

use App\Models\MatterOneDriveFolder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Inside a matter's OneDrive folder, as a file manager: what's in a folder,
 * a file to download, files put in, a folder made, renamed or deleted (to
 * the owner's OneDrive recycle bin). Nothing outside the matter's folder is
 * reached — every item asked for is checked to be within it.
 */
class MatterOneDriveExplorer
{
    /**
     * A folder's listings, kept — read again when the matter is opened or
     * Refresh is pressed (refresh(): a new version of them all), at the
     * latest after this long.
     */
    private const LISTING_SECONDS = 3600;

    /** @var array<int, string> the matter folder's own place, by folder */
    private array $roots = [];

    public function __construct(private readonly OneDriveClient $client) {}

    /**
     * What's in this folder (the matter folder when none): folders first,
     * then files, by name.
     *
     * @return list<array{id: string, name: string, webUrl: string, folder: bool, children: ?int, size: ?int, modified: ?string, mime: ?string}>
     */
    public function list(MatterOneDriveFolder $folder, ?string $itemId = null): array
    {
        $itemId = $this->inside($folder, $itemId);

        $items = $this->children($folder, $itemId);

        usort($items, fn (array $a, array $b): int => [$b['folder'], mb_strtolower($a['name'])] <=> [$a['folder'], mb_strtolower($b['name'])]);

        return $items;
    }

    /** Every listing of the folder read afresh next time — a new version of them. */
    public function refresh(MatterOneDriveFolder $folder): void
    {
        Cache::forever($this->versionKey($folder), Str::random(10));
    }

    /**
     * What's in a folder already known to be in the matter's — as kept,
     * else read.
     *
     * @return list<array{id: string, name: string, webUrl: string, folder: bool, children: ?int, size: ?int, modified: ?string, mime: ?string}>
     */
    private function children(MatterOneDriveFolder $folder, string $itemId): array
    {
        return Cache::remember($this->listingKey($folder, $itemId), self::LISTING_SECONDS,
            fn (): array => $this->client->children($this->user($folder), $itemId));
    }

    /** A link to download a file, good for a few minutes. */
    public function downloadUrl(MatterOneDriveFolder $folder, string $itemId): string
    {
        $info = $this->info($folder, $itemId);

        if ($info['folder'] || blank($info['downloadUrl'])) {
            throw new RuntimeException(__('Only a file can be downloaded.'));
        }

        return (string) $info['downloadUrl'];
    }

    /**
     * Files to pick from: those found by these words anywhere in the
     * matter's folder — or, with none, those at its top and in each folder
     * directly in it. Each with where it is ("01 المراسلات").
     *
     * @return list<array{id: string, name: string, where: string}>
     */
    public function files(MatterOneDriveFolder $folder, string $words = ''): array
    {
        $root = $this->rootId($folder);

        if (trim($words) !== '') {
            $rootPath = $this->rootPath($folder);

            return collect($this->client->search($this->user($folder), $root, $words))
                ->reject(fn (array $item) => $item['folder'])
                ->map(fn (array $item): array => [...$item, 'path' => self::normalPath($item['path'])])
                ->filter(fn (array $item) => $item['path'] === $rootPath || str_starts_with($item['path'], $rootPath.'/'))
                ->map(fn (array $item): array => ['id' => $item['id'], 'name' => $item['name'], 'where' => ltrim(substr($item['path'], strlen($rootPath)), '/')])
                ->values()
                ->all();
        }

        $top = $this->list($folder);
        $files = collect($top)->reject(fn (array $i) => $i['folder'])->map(fn (array $i): array => ['id' => $i['id'], 'name' => $i['name'], 'where' => '']);

        foreach (collect($top)->filter(fn (array $i) => $i['folder'] && $i['children'] > 0)->take(15) as $sub) {
            $files = $files->concat(collect($this->children($folder, $sub['id']))
                ->reject(fn (array $i) => $i['folder'])
                ->map(fn (array $i): array => ['id' => $i['id'], 'name' => $i['name'], 'where' => $sub['name']]));
        }

        return $files->take(100)->values()->all();
    }

    /**
     * A file's name and contents, to attach to an email — one in the
     * matter's folder, of a size an email takes.
     *
     * @return array{name: string, contents: string}
     */
    public function fetch(MatterOneDriveFolder $folder, string $itemId, int $maxBytes = 20 * 1024 * 1024): array
    {
        $info = $this->info($folder, $itemId);

        if ($info['folder'] || blank($info['downloadUrl'])) {
            throw new RuntimeException(__('Only a file can be downloaded.'));
        }

        if (($info['size'] ?? 0) > $maxBytes) {
            throw new RuntimeException(__(':name is too large to attach to an email (over :size MB).', ['name' => $info['name'], 'size' => intdiv($maxBytes, 1024 * 1024)]));
        }

        return ['name' => $info['name'], 'contents' => $this->client->download((string) $info['downloadUrl'])];
    }

    /** One file's name, for what was picked. */
    public function name(MatterOneDriveFolder $folder, string $itemId): string
    {
        return Cache::remember('onedrive-name:'.$folder->getKey().':'.md5($itemId), 600, fn (): string => $this->info($folder, $itemId)['name']);
    }

    public function upload(MatterOneDriveFolder $folder, ?string $itemId, string $name, string $contents, string $mime): void
    {
        $itemId = $this->inside($folder, $itemId);
        $this->client->upload($this->user($folder), $itemId, $name, $contents, $mime);
        $this->forget($folder, $itemId);
    }

    public function createFolder(MatterOneDriveFolder $folder, ?string $itemId, string $name): void
    {
        $itemId = $this->inside($folder, $itemId);
        $this->client->createFolder($this->user($folder), $itemId, self::cleanName($name));
        $this->forget($folder, $itemId);
    }

    /** In the folder it's in (shown now), renamed. */
    public function rename(MatterOneDriveFolder $folder, ?string $parentId, string $itemId, string $name): void
    {
        $this->info($folder, $itemId, notTheRoot: true);
        $this->client->rename($this->user($folder), $itemId, self::cleanName($name));
        $this->forget($folder, $this->inside($folder, $parentId));
    }

    /** To the owner's OneDrive recycle bin, from where it can be restored. */
    public function delete(MatterOneDriveFolder $folder, ?string $parentId, string $itemId): void
    {
        $this->info($folder, $itemId, notTheRoot: true);
        $this->client->delete($this->user($folder), $itemId);
        $this->forget($folder, $this->inside($folder, $parentId));
    }

    /**
     * The folder asked for — the matter folder itself when none — once
     * checked to be in it.
     */
    private function inside(MatterOneDriveFolder $folder, ?string $itemId): string
    {
        if (blank($itemId) || $itemId === $folder->drive_item_id) {
            return $this->rootId($folder);
        }

        if (! $this->info($folder, $itemId)['folder']) {
            throw new RuntimeException(__('That is not a folder.'));
        }

        return $itemId;
    }

    /**
     * An item, only when it's in the matter's folder.
     *
     * @return array{id: string, name: string, webUrl: string, path: string, folder: bool, size: ?int, downloadUrl: ?string}
     */
    private function info(MatterOneDriveFolder $folder, string $itemId, bool $notTheRoot = false): array
    {
        if ($itemId === $this->rootId($folder)) {
            if ($notTheRoot) {
                throw new RuntimeException(__('The matter\'s own folder can\'t be changed here.'));
            }

            return $this->client->itemInfo($this->user($folder), $itemId);
        }

        $info = $this->client->itemInfo($this->user($folder), $itemId);
        $root = $this->rootPath($folder);
        $path = self::normalPath($info['path']);

        if ($path !== $root && ! str_starts_with($path, $root.'/')) {
            throw new RuntimeException(__('That is not in this matter\'s folder.'));
        }

        return $info;
    }

    /** "/drive/root:/Work/Matters/639-2025" — where the matter folder is, with its name. */
    private function rootPath(MatterOneDriveFolder $folder): string
    {
        return $this->roots[$folder->getKey()] ??= (function () use ($folder): string {
            $info = $this->client->itemInfo($this->user($folder), $this->rootId($folder));

            return self::normalPath($info['path'].'/'.$info['name']);
        })();
    }

    private function rootId(MatterOneDriveFolder $folder): string
    {
        if (! $folder->isCreated() || blank($folder->drive_item_id)) {
            throw new RuntimeException(__('This matter has no folder in OneDrive yet.'));
        }

        return (string) $folder->drive_item_id;
    }

    private function user(MatterOneDriveFolder $folder): string
    {
        $email = $folder->party?->onedrive_email;

        if (blank($email)) {
            throw new RuntimeException(__('The assistant has no OneDrive account set.'));
        }

        return (string) $email;
    }

    private function listingKey(MatterOneDriveFolder $folder, string $itemId): string
    {
        $version = Cache::rememberForever($this->versionKey($folder), fn (): string => Str::random(10));

        return 'onedrive-listing:'.$folder->getKey().':'.$version.':'.md5($itemId);
    }

    private function versionKey(MatterOneDriveFolder $folder): string
    {
        return 'onedrive-listing-version:'.$folder->getKey();
    }

    private function forget(MatterOneDriveFolder $folder, string $itemId): void
    {
        Cache::forget($this->listingKey($folder, $itemId));
    }

    private static function normalPath(string $path): string
    {
        return rtrim(rawurldecode($path), '/');
    }

    /** A name OneDrive takes: no \ / : * ? " < > | and no leading or trailing dots or spaces. */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace(['/[\\\\\/:*?"<>|]+/u', '/\s+/u'], ' ', $name), " .\t");

        if ($name === '') {
            throw new RuntimeException(__('Give it a name.'));
        }

        return mb_substr($name, 0, 200);
    }
}
