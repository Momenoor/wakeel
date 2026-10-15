<?php

use App\Models\MailSender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The mailboxes that were set in config/mail_senders.php (with their
 * passwords in .env) move into the mail_senders table — the one place
 * senders are kept now, edited under Settings → Mail senders. A sender
 * already there under the same code is left as it is. The letters and
 * campaigns that name a sender by its code keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Not for a test database: it starts empty, whatever the developer's .env holds.
        if (! Schema::hasTable('mail_senders') || app()->environment('testing')) {
            return;
        }

        $file = database_path('legacy/mail_senders.php');
        $senders = is_file($file) ? (array) require $file : [];

        foreach ($senders as $key => $sender) {
            if (MailSender::query()->where('key', $key)->exists()) {
                continue;
            }

            MailSender::query()->forceCreate([
                'key' => $key,
                'name' => $sender['name'] ?? $key,
                'address' => $sender['address'] ?? ($sender['username'] ?? ''),
                'driver' => MailSender::SMTP,
                'host' => $sender['host'] ?? null,
                'port' => $sender['port'] ?? null,
                'encryption' => $sender['encryption'] ?? null,
                'username' => $sender['username'] ?? null,
                'password' => $sender['password'] ?? null,
                'signature' => $sender['signature'] ?? null,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        // Not moved back: the senders stay in the database.
    }
};
