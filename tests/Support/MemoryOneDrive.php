<?php

namespace Tests\Support;

use App\Services\MMS\OneDriveClient;

/**
 * A OneDrive in memory: folders and files under a root, made, listed and moved as Graph does — a file's link its
 * path, so a moved one's changes.
 */
class MemoryOneDrive extends OneDriveClient
{
    /** @var array<string, array{name: string, parent: ?string, folder: bool}> */
    public array $items = [];

    /** @var array<string, string> a file's contents, by its id */
    public array $contents = [];

    private int $next = 0;

    public function __construct(private readonly string $root = 'root')
    {
        $this->items[$root] = ['name' => '', 'parent' => null, 'folder' => true];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** A file put at a path under the root (its folders made) — its id. */
    public function put(string $path, string $contents = ''): string
    {
        $segments = self::segments($path);
        $name = array_pop($segments);
        $parent = $segments === [] ? $this->root : $this->ensureFolder('', implode('/', $segments), $this->root)['id'];
        $id = $this->add($name, $parent, false);
        $this->contents[$id] = $contents;

        return $id;
    }

    /** An item's path under the root. */
    public function pathOf(string $id): string
    {
        $names = [];

        while ($id !== $this->root && isset($this->items[$id])) {
            array_unshift($names, $this->items[$id]['name']);
            $id = (string) $this->items[$id]['parent'];
        }

        return implode('/', $names);
    }

    /**
     * The paths of the files there are, under the root.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = array_map(fn (string $id): string => $this->pathOf($id), array_keys(array_filter($this->items, fn (array $item): bool => ! $item['folder'])));
        sort($files);

        return $files;
    }

    public function ensureFolder(string $user, string $path, ?string $parentId = null): array
    {
        $id = $parentId ?? $this->root;

        foreach (self::segments($path) as $segment) {
            $found = array_key_first(array_filter($this->items, fn (array $item): bool => $item['folder'] && $item['parent'] === $id && mb_strtolower($item['name']) === mb_strtolower($segment)));
            $id = $found ?? $this->add($segment, $id, true);
        }

        return ['id' => $id, 'webUrl' => $this->url($id)];
    }

    public function childFolders(string $user, ?string $folderId = null): array
    {
        return array_values(array_map(fn (array $item): array => ['id' => $item['id'], 'name' => $item['name'], 'webUrl' => $item['webUrl']], array_filter($this->children($user, $folderId ?? $this->root), fn (array $item): bool => $item['folder'])));
    }

    public function children(string $user, string $folderId): array
    {
        $children = [];

        foreach ($this->items as $id => $item) {
            if ($item['parent'] === $folderId) {
                $children[] = ['id' => (string) $id, 'name' => $item['name'], 'webUrl' => $this->url((string) $id), 'folder' => $item['folder'], 'children' => $item['folder'] ? count(array_filter($this->items, fn (array $child): bool => $child['parent'] === (string) $id)) : null, 'size' => $item['folder'] ? null : strlen($this->contents[$id] ?? ''), 'modified' => null, 'mime' => null];
            }
        }

        return $children;
    }

    public function childrenOfMany(string $user, array $folderIds): array
    {
        return collect($folderIds)->mapWithKeys(fn (string $id): array => [$id => $this->children($user, $id)])->all();
    }

    public function itemInfo(string $user, string $itemId): array
    {
        $item = $this->items[$itemId];

        return ['id' => $itemId, 'name' => $item['name'], 'webUrl' => $this->url($itemId), 'path' => '', 'folder' => $item['folder'], 'size' => null, 'mime' => null, 'downloadUrl' => $item['folder'] ? null : 'memory://'.$itemId];
    }

    public function download(string $downloadUrl): string
    {
        return $this->contents[substr($downloadUrl, strlen('memory://'))] ?? '';
    }

    public function upload(string $user, string $folderId, string $name, string $contents, string $mime = 'application/octet-stream', bool $replace = false): array
    {
        $id = $this->add($name, $folderId, false);
        $this->contents[$id] = $contents;

        return ['id' => $id, 'name' => $name, 'webUrl' => $this->url($id)];
    }

    public function move(string $user, string $itemId, string $parentId): array
    {
        $this->items[$itemId]['parent'] = $parentId;

        return ['id' => $itemId, 'webUrl' => $this->url($itemId), 'name' => $this->items[$itemId]['name']];
    }

    private function add(string $name, string $parent, bool $folder): string
    {
        $id = 'item-'.++$this->next;
        $this->items[$id] = ['name' => $name, 'parent' => $parent, 'folder' => $folder];

        return $id;
    }

    private function url(string $id): string
    {
        return 'https://od/'.$this->pathOf($id);
    }
}
