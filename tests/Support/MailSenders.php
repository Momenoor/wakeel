<?php

namespace Tests\Support;

use App\Models\MailSender;

/**
 * A mail sender in the database, as Settings → Mail senders keeps them.
 */
class MailSenders
{
    /**
     * @param  array<string, mixed>  $sender  username, address, name, password, host, port, encryption, signature, keep_all_inbox …
     */
    public static function add(string $key, array $sender = []): MailSender
    {
        return MailSender::query()->forceCreate([
            'key' => $key,
            'name' => $sender['name'] ?? $key,
            'address' => $sender['address'] ?? $key.'@example.com',
            'driver' => $sender['driver'] ?? MailSender::SMTP,
            'host' => $sender['host'] ?? 'mail.example.com',
            'port' => $sender['port'] ?? 587,
            'encryption' => $sender['encryption'] ?? 'tls',
            'username' => $sender['username'] ?? ($sender['address'] ?? $key.'@example.com'),
            'password' => $sender['password'] ?? 'secret',
            'signature' => $sender['signature'] ?? null,
            'is_active' => true,
            'keep_all_inbox' => $sender['keep_all_inbox'] ?? false,
            'files_forwards' => $sender['files_forwards'] ?? false,
        ]);
    }
}
