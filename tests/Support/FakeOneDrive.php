<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small OneDrive in memory, for tests that must follow folders around: making
 * them, listing what is in one, moving one into another, removing one. It answers
 * the Graph calls the app makes (see OneDriveClient). Folders only; files are
 * counted as children by name.
 */
class FakeOneDrive
{
    /** @var array<string, array{name: string, parent: ?string, folder: bool}> id => item */
    public array $items = [];

    private int $next = 0;

    private const ROOT = 'root';

    public function __construct()
    {
        $this->items[self::ROOT] = ['name' => '', 'parent' => null, 'folder' => true];
    }

    public function install(): self
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn (Request $request) => $this->answer($request));

        return $this;
    }

    /** "Work/Matters/2026-1010" => the folder's id, or null. */
    public function find(string $path): ?string
    {
        $id = self::ROOT;

        foreach (array_filter(explode('/', $path), 'strlen') as $segment) {
            $id = $this->child($id, $segment);

            if ($id === null) {
                return null;
            }
        }

        return $id;
    }

    /** @return list<string> the names inside a folder (by path), sorted */
    public function names(string $path): array
    {
        $id = $this->find($path);
        $names = $id === null ? [] : array_map(fn (string $child): string => $this->items[$child]['name'], $this->childrenOf($id));
        sort($names);

        return $names;
    }

    /** Every folder as a path, sorted — to see the whole tree. @return list<string> */
    public function tree(): array
    {
        $paths = [];

        foreach (array_keys($this->items) as $id) {
            if ($id !== self::ROOT) {
                $paths[] = $this->pathOf($id);
            }
        }
        sort($paths);

        return $paths;
    }

    public function pathOf(string $id): string
    {
        $parts = [];

        while ($id !== null && $id !== self::ROOT) {
            array_unshift($parts, $this->items[$id]['name']);
            $id = $this->items[$id]['parent'];
        }

        return implode('/', $parts);
    }

    private function child(string $parent, string $name): ?string
    {
        foreach ($this->childrenOf($parent) as $id) {
            if (mb_strtolower($this->items[$id]['name']) === mb_strtolower($name)) {
                return $id;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function childrenOf(string $parent): array
    {
        return array_values(array_keys(array_filter($this->items, fn (array $item): bool => $item['parent'] === $parent)));
    }

    private function resource(string $id): array
    {
        $base = ['id' => $id, 'name' => $this->items[$id]['name'], 'webUrl' => 'https://od.test/'.$id];

        return $this->items[$id]['folder']
            ? [...$base, 'folder' => ['childCount' => count($this->childrenOf($id))]]
            : [...$base, 'file' => ['mimeType' => 'application/octet-stream'], 'size' => 1];
    }

    private function makeFile(string $parent, string $name): string
    {
        $id = 'f'.(++$this->next);
        $this->items[$id] = ['name' => $name, 'parent' => $parent, 'folder' => false];

        return $id;
    }

    private function answer(Request $request)
    {
        $url = rawurldecode($request->url());

        if (str_contains($url, 'login.microsoftonline.com')) {
            return Http::response(['access_token' => 'token']);
        }

        $path = (string) preg_replace('~^https://graph\.microsoft\.com/v1\.0/users/[^/]+/drive/~', '', strtok($url, '?'));
        $method = $request->method();

        // A file put in a folder: items/{id}:/name:/content
        if ($method === 'PUT' && preg_match('~^(root|items/([^/:]+)):/(.+):/content$~', $path, $m)) {
            $parent = $m[1] === 'root' ? self::ROOT : $m[2];
            $file = $this->makeFile($parent, $m[3]);

            return Http::response($this->resource($file), 201);
        }

        // root:/path or items/{id}:/segment
        if (preg_match('~^(root|items/([^/:]+)):/(.+)$~', $path, $m)) {
            $parent = $m[1] === 'root' ? self::ROOT : $m[2];
            $id = $this->find2($parent, $m[3]);

            return $id === null ? Http::response(['error' => ['message' => 'not found']], 404) : Http::response($this->resource($id));
        }

        if (! preg_match('~^(root|items/([^/]+))(/children)?$~', $path, $m)) {
            return Http::response(['value' => []]);
        }

        $id = $m[1] === 'root' ? self::ROOT : $m[2];
        $children = isset($m[3]) && $m[3] === '/children';

        if ($children && $method === 'POST') {
            $name = $request->data()['name'];

            if ($this->child($id, $name) !== null) {
                return Http::response(['error' => ['code' => 'nameAlreadyExists']], 409);
            }

            return Http::response($this->resource($this->make($id, $name)), 201);
        }

        if ($children && $method === 'GET') {
            return Http::response(['value' => array_map(fn (string $child): array => $this->resource($child), $this->childrenOf($id))]);
        }

        if ($method === 'PATCH' && isset($request->data()['parentReference']['id'])) {
            $to = $request->data()['parentReference']['id'];
            $name = $this->items[$id]['name'];

            // A folder of that name there already: renamed, as OneDrive does.
            for ($i = 1; $this->child($to, $name) !== null; $i++) {
                $name = $this->items[$id]['name'].' '.$i;
            }

            $this->items[$id]['parent'] = $to;
            $this->items[$id]['name'] = $name;

            return Http::response($this->resource($id));
        }

        if ($method === 'DELETE') {
            unset($this->items[$id]);

            return Http::response('', 204);
        }

        return isset($this->items[$id]) ? Http::response($this->resource($id)) : Http::response(['error' => ['message' => 'not found']], 404);
    }

    private function find2(string $parent, string $path): ?string
    {
        $id = $parent;

        foreach (array_filter(explode('/', $path), 'strlen') as $segment) {
            $id = $this->child($id, $segment);

            if ($id === null) {
                return null;
            }
        }

        return $id;
    }

    public function make(string $parent, string $name): string
    {
        $id = 'f'.(++$this->next);
        $this->items[$id] = ['name' => $name, 'parent' => $parent, 'folder' => true];

        return $id;
    }

    /** Makes the folders of a path (as the app does) and returns the last one's id. */
    public function ensure(string $path): string
    {
        $id = self::ROOT;

        foreach (array_filter(explode('/', $path), 'strlen') as $segment) {
            $id = $this->child($id, $segment) ?? $this->make($id, $segment);
        }

        return $id;
    }
}
