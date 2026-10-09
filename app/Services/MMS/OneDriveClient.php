<?php

namespace App\Services\MMS;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Folders in a Microsoft 365 user's OneDrive, through Microsoft Graph with
 * an Azure app registration that has the Files.ReadWrite.All application
 * permission (admin consent). The Outlook calendar app (MICROSOFT_* in
 * .env) is used when set up, otherwise the mail app (MICROSOFT_GRAPH_*).
 */
class OneDriveClient
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @return array{tenant_id: string, client_id: string, client_secret: string}|null
     */
    private function credentials(): ?array
    {
        foreach ([config('services.outlook'), config('mail.mailers.microsoft-graph')] as $config) {
            if (filled($config['tenant_id'] ?? null) && filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null)) {
                return ['tenant_id' => $config['tenant_id'], 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret']];
            }
        }

        return null;
    }

    /**
     * Makes sure every folder along the path exists — under the user's
     * OneDrive root, or under the folder with the given id — creating the
     * missing ones, and returns the last. Existing folders, and whatever is
     * in them, are left untouched.
     *
     * @return array{id: string, webUrl: string}
     */
    public function ensureFolder(string $user, string $path, ?string $parentId = null): array
    {
        $drive = "/users/{$this->user($user)}/drive";
        $base = $parentId === null ? "{$drive}/root" : "{$drive}/items/".rawurlencode($parentId);
        $item = null;

        foreach (self::segments($path) as $segment) {
            $response = $this->request('post', "{$base}/children", [
                'name' => $segment,
                'folder' => new \stdClass,
                '@microsoft.graph.conflictBehavior' => 'fail',
            ], allow409: true);

            // 409: already there — use it as it is.
            $item = $response->status() === 409
                ? $this->item($this->request('get', "{$base}:/".rawurlencode($segment)))
                : $this->item($response);

            $base = "{$drive}/items/".rawurlencode($item['id']);
        }

        return $item ?? $this->item($this->request('get', $base));
    }

    /**
     * The folder at this path under the user's OneDrive root, or null when
     * there is none.
     *
     * @return array{id: string, webUrl: string}|null
     */
    public function findFolder(string $user, string $path): ?array
    {
        $response = $this->request('get', "/users/{$this->user($user)}/drive/root:/".implode('/', array_map('rawurlencode', self::segments($path))), allow404: true);

        return $response->status() === 404 ? null : $this->item($response);
    }

    /**
     * The folders directly inside a folder (by id), or the user's OneDrive
     * root — every page of them.
     *
     * @return list<array{id: string, name: string, webUrl: string}>
     */
    public function childFolders(string $user, ?string $folderId = null): array
    {
        $drive = "/users/{$this->user($user)}/drive";
        $uri = ($folderId === null ? "{$drive}/root" : "{$drive}/items/".rawurlencode($folderId)).'/children?$select=id,name,webUrl,folder&$top=200';
        $folders = [];

        while ($uri !== null) {
            $response = $this->request('get', $uri);

            foreach ((array) $response->json('value') as $item) {
                if (isset($item['folder'])) {
                    $folders[] = ['id' => (string) $item['id'], 'name' => (string) $item['name'], 'webUrl' => (string) ($item['webUrl'] ?? '')];
                }
            }

            // The next page, as a path under the API root.
            $next = $response->json('@odata.nextLink');
            $uri = is_string($next) && str_starts_with($next, self::GRAPH) ? substr($next, strlen(self::GRAPH)) : null;
        }

        return $folders;
    }

    /**
     * Renames a file or folder — its contents untouched. Fails (409) when
     * the folder it is in has one of that name already.
     *
     * @return array{id: string, webUrl: string}
     */
    public function rename(string $user, string $itemId, string $name): array
    {
        return $this->item($this->request('patch', "/users/{$this->user($user)}/drive/items/".rawurlencode($itemId), [
            'name' => $name,
            '@microsoft.graph.conflictBehavior' => 'fail',
        ]));
    }

    /**
     * Deletes a file or folder — OneDrive keeps it in the user's recycle
     * bin, from where it can be restored.
     */
    public function delete(string $user, string $itemId): void
    {
        $this->request('delete', "/users/{$this->user($user)}/drive/items/".rawurlencode($itemId), allow404: true);
    }

    /**
     * Puts a file in a folder — renamed ("name 1.pdf") when one of that
     * name is there already.
     *
     * @return array{id: string, webUrl: string}
     */
    public function upload(string $user, string $folderId, string $name, string $contents, string $mime = 'application/octet-stream'): array
    {
        $uri = "/users/{$this->user($user)}/drive/items/".rawurlencode($folderId).':/'.rawurlencode($name).':/content?@microsoft.graph.conflictBehavior=rename';
        $response = Http::withToken($this->token())->acceptJson()->timeout(120)
            ->withBody($contents, $mime)
            ->put(self::GRAPH.$uri);

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Graph '.$response->status().': '.($response->json('error.message') ?: $response->body()));
        }

        return $this->item($response);
    }

    /**
     * Checks the app can reach this user's OneDrive — for the settings
     * page's connection test.
     */
    public function test(string $user): string
    {
        return (string) $this->request('get', "/users/{$this->user($user)}/drive/root")->json('webUrl');
    }

    /**
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('#[/\\\\]+#', $path) ?: []),
            fn (string $s): bool => $s !== '',
        ));
    }

    /**
     * @param  list<string>  $segments
     */
    private static function encode(array $segments): string
    {
        return implode('/', array_map('rawurlencode', $segments));
    }

    private function user(string $user): string
    {
        return rawurlencode(trim($user));
    }

    /**
     * @return array{id: string, webUrl: string}
     */
    private function item(Response $response): array
    {
        return ['id' => (string) $response->json('id'), 'webUrl' => (string) $response->json('webUrl')];
    }

    private function request(string $method, string $uri, array $body = [], bool $allow409 = false, bool $allow404 = false): Response
    {
        $http = Http::withToken($this->token())->acceptJson()->timeout(20);
        $response = match ($method) {
            'get' => $http->get(self::GRAPH.$uri),
            'delete' => $http->delete(self::GRAPH.$uri),
            'patch' => $http->patch(self::GRAPH.$uri, $body),
            default => $http->post(self::GRAPH.$uri, $body),
        };

        if ($response->successful() || ($allow409 && $response->status() === 409) || ($allow404 && $response->status() === 404)) {
            return $response;
        }

        // A clear message for the matter page and the log, not raw JSON.
        $message = $response->json('error.message') ?: $response->body();

        throw new RuntimeException(match ($response->status()) {
            401, 403 => __('Microsoft 365 refused access (:status). Check the app registration has the Files.ReadWrite.All application permission with admin consent.', ['status' => $response->status()]).' '.$message,
            404 => __('OneDrive not found for this account. Check the email on the assistant\'s profile, and that they have signed in to OneDrive at least once.').' '.$message,
            default => 'Microsoft Graph '.$response->status().': '.$message,
        });
    }

    private function token(): string
    {
        $config = $this->credentials();

        if ($config === null) {
            throw new RuntimeException(__('Microsoft 365 is not set up: add the app registration (MICROSOFT_TENANT_ID, MICROSOFT_CLIENT_ID, MICROSOFT_CLIENT_SECRET) to .env.'));
        }

        return Cache::remember('onedrive_graph_token_'.md5($config['client_id']), 50 * 60, function () use ($config): string {
            $response = Http::asForm()->timeout(15)
                ->post('https://login.microsoftonline.com/'.$config['tenant_id'].'/oauth2/v2.0/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                ]);

            if (blank($response->json('access_token'))) {
                throw new RuntimeException(__('Could not sign in to Microsoft 365: :error', ['error' => $response->json('error_description') ?: $response->body()]));
            }

            return $response->json('access_token');
        });
    }
}
