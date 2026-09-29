<?php

namespace App\Services\Push;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Minishlink\WebPush\VAPID;
use RuntimeException;
use Throwable;

/**
 * The key pair that identifies Wakeel to the browsers' push services.
 *
 * VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY in .env win when set. Otherwise the
 * keys are made once, the first time they are needed, and kept in the
 * settings table (the private key encrypted with APP_KEY) — so nothing has
 * to be run on the server. Changing them later means every browser has to
 * subscribe again, which the page does by itself on the next visit.
 */
class VapidKeys
{
    private const PUBLIC = 'webpush_public_key';

    private const PRIVATE = 'webpush_private_key';

    /**
     * @return array{public: string, private: string}
     */
    public static function get(): array
    {
        if (filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'))) {
            return ['public' => (string) config('services.webpush.public_key'), 'private' => (string) config('services.webpush.private_key')];
        }

        $public = Setting::get(self::PUBLIC);
        $private = Setting::get(self::PRIVATE);

        if (filled($public) && filled($private)) {
            return ['public' => (string) $public, 'private' => Crypt::decryptString((string) $private)];
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $exception) {
            throw new RuntimeException('Could not create the Web Push keys: PHP\'s OpenSSL cannot make EC keys here (on Windows, set OPENSSL_CONF). '.$exception->getMessage(), 0, $exception);
        }

        Setting::set(self::PUBLIC, $keys['publicKey'], 'webpush');
        Setting::set(self::PRIVATE, Crypt::encryptString($keys['privateKey']), 'webpush');

        return ['public' => $keys['publicKey'], 'private' => $keys['privateKey']];
    }

    /**
     * The public key for the page, or null when keys cannot be had — the
     * page then simply does not offer push.
     */
    public static function publicKey(): ?string
    {
        // Asked on every page: after a failure, wait an hour before trying
        // (and logging) again.
        if (Cache::has('webpush-keys-failed')) {
            return null;
        }

        try {
            return self::get()['public'];
        } catch (Throwable $exception) {
            report($exception);
            Cache::put('webpush-keys-failed', true, now()->addHour());

            return null;
        }
    }
}
