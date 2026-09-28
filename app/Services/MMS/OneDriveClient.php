<?php

namespace App\Services\MMS;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Folders in a Microsoft 365 user's OneDrive, through Microsoft Graph with
 * the same Azure app registration the Microsoft 365 mail sender uses
 * (MICROSOFT_GRAPH_* in .env). The app needs the Files.ReadWrite.All
 * application permission, with admin consent.
 */
class OneDriveClient
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public function isConfigured(): bool
    {
        $config = config('mail.mailers.microsoft-graph');

        return filled($config['tenant_id'] ?? null) && filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null);
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

    private function request(string $method, string $uri, array $body = [], bool $allow409 = false): Response
    {
        $http = Http::withToken($this->token())->acceptJson()->timeout(20);
        $response = $method === 'get' ? $http->get(self::GRAPH.$uri) : $http->post(self::GRAPH.$uri, $body);

        if ($response->successful() || ($allow409 && $response->status() === 409)) {
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
        if (! $this->isConfigured()) {
            throw new RuntimeException(__('Microsoft 365 is not set up: add MICROSOFT_GRAPH_TENANT_ID, MICROSOFT_GRAPH_CLIENT_ID and MICROSOFT_GRAPH_CLIENT_SECRET to .env.'));
        }

        $config = config('mail.mailers.microsoft-graph');

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
