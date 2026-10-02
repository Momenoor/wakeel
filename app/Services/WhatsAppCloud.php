<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The WhatsApp Cloud API (Meta) on the number set up for notifications:
 * template messages with a file in their header, plain replies inside the
 * 24 hours after someone writes, and the files people send in — fetched
 * when the webhook says one arrived.
 */
class WhatsAppCloud
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';

    /** The token Meta's webhook set-up must give back (Settings). */
    public const VERIFY_TOKEN = 'whatsapp_verify_token';

    /** The Meta app's secret: webhook calls are signed with it (Settings). */
    public const APP_SECRET = 'whatsapp_app_secret';

    public static function configured(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_id'));
    }

    public static function verifyToken(): string
    {
        $token = (string) Setting::get(self::VERIFY_TOKEN, '');
        if ($token === '') {
            $token = Str::random(32);
            Setting::set(self::VERIFY_TOKEN, $token, 'whatsapp');
        }

        return $token;
    }

    public static function appSecret(): string
    {
        $secret = (string) Setting::get(self::APP_SECRET, '');

        return $secret !== '' ? decrypt($secret) : (string) config('services.whatsapp.app_secret', '');
    }

    /**
     * A webhook call really from Meta: signed with the app secret. Without
     * a secret set, none is accepted.
     */
    public static function validSignature(string $payload, ?string $header): bool
    {
        $secret = self::appSecret();

        return $secret !== '' && filled($header)
            && hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), (string) $header);
    }

    /**
     * Uploads a file for a message; its media id.
     */
    public function upload(string $path, string $mime, string $name): string
    {
        $response = $this->http()
            ->attach('file', (string) file_get_contents($path), $name, ['Content-Type' => $mime])
            ->post(self::GRAPH.'/'.config('services.whatsapp.phone_id').'/media', [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
            ]);

        return (string) $this->ok($response)->json('id');
    }

    /**
     * Sends an approved template, its parameters by name, a document in its
     * header when given; the message id (replies refer to it).
     *
     * @param  array<string, string>  $parameters
     * @param  array{id: string, filename: string}|null  $document
     */
    public function sendTemplate(string $to, string $name, string $language, array $parameters, ?array $document = null): string
    {
        $components = [];

        if ($document !== null) {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => $document]]];
        }

        if ($parameters !== []) {
            $components[] = ['type' => 'body', 'parameters' => collect($parameters)
                ->map(fn (string $text, string $key) => ['type' => 'text', 'parameter_name' => $key, 'text' => $text])
                ->values()->all()];
        }

        $response = $this->http()->post(self::GRAPH.'/'.config('services.whatsapp.phone_id').'/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => array_filter([
                'name' => $name,
                'language' => ['code' => $language],
                'components' => $components ?: null,
            ]),
        ]);

        return (string) $this->ok($response)->json('messages.0.id');
    }

    /**
     * A plain message — allowed only within 24 hours of theirs.
     */
    public function sendText(string $to, string $text): void
    {
        $this->ok($this->http()->post(self::GRAPH.'/'.config('services.whatsapp.phone_id').'/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text],
        ]));
    }

    /**
     * A file someone sent in.
     *
     * @return array{data: string, mime: string}
     */
    public function download(string $mediaId): array
    {
        $info = $this->ok($this->http()->get(self::GRAPH.'/'.$mediaId));
        $file = $this->ok($this->http()->timeout(60)->get((string) $info->json('url')));

        return ['data' => $file->body(), 'mime' => (string) ($info->json('mime_type') ?: $file->header('Content-Type'))];
    }

    private function http(): PendingRequest
    {
        if (! self::configured()) {
            throw new RuntimeException(__('WhatsApp is not set up (WHATSAPP_PHONE_ID and WHATSAPP_TOKEN in .env).'));
        }

        return Http::withToken((string) config('services.whatsapp.token'))->acceptJson()->timeout(30);
    }

    private function ok(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $error = $response->json('error');
        $message = is_array($error)
            ? trim(($error['message'] ?? '').' '.($error['error_data']['details'] ?? ''))
            : $response->body();

        throw new RuntimeException('WhatsApp '.$response->status().': '.$message);
    }
}
