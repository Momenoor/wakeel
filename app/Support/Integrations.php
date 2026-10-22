<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * The office's own outside services, entered in Settings →
 * Integrations: Microsoft 365 (OneDrive, Outlook calendar, Microsoft 365
 * mailboxes), Pusher (live updates), the cron-job.org link (scheduled tasks
 * and the queue), WhatsApp and Azure Blob Storage (where attached files are
 * kept). Each office has its own; nothing is shared.
 *
 * Applied over the configuration on every request, ahead of .env (which
 * stays as the fallback for an installation set up before these settings).
 * A service with its settings incomplete is simply off: its buttons hide,
 * and live updates fall back to polling.
 */
class Integrations
{
    public const MS_TENANT = 'integration_ms_tenant_id';

    public const MS_CLIENT = 'integration_ms_client_id';

    public const MS_SECRET = 'integration_ms_client_secret';

    public const MS_CALENDAR = 'integration_ms_calendar_mailbox';

    public const PUSHER_APP = 'integration_pusher_app_id';

    public const PUSHER_KEY = 'integration_pusher_key';

    public const PUSHER_SECRET = 'integration_pusher_secret';

    public const PUSHER_CLUSTER = 'integration_pusher_cluster';

    public const CRON_TOKEN = 'integration_cron_token';

    public const WHATSAPP_PHONE = 'integration_whatsapp_phone_id';

    public const WHATSAPP_TOKEN = 'integration_whatsapp_token';

    public const AZURE_ACCOUNT = 'integration_azure_account';

    public const AZURE_KEY = 'integration_azure_key';

    public const AZURE_CONTAINER = 'integration_azure_container';

    /** "1": new attached files are kept in Azure (those before stay where they are until copied). */
    public const AZURE_ATTACHMENTS = 'integration_azure_attachments';

    /** The storage Azure is, once set (config/filesystems.php has no such disk until then). */
    public const AZURE_DISK = 'azure';

    /** The system's documents that were on the public disk (App\Support\Documents): in the same container, under public/. */
    public const AZURE_PUBLIC_DISK = 'azure-public';

    public const KEYS = [
        self::MS_TENANT, self::MS_CLIENT, self::MS_SECRET, self::MS_CALENDAR,
        self::PUSHER_APP, self::PUSHER_KEY, self::PUSHER_SECRET, self::PUSHER_CLUSTER,
        self::CRON_TOKEN,
        self::WHATSAPP_PHONE, self::WHATSAPP_TOKEN,
        self::AZURE_ACCOUNT, self::AZURE_KEY, self::AZURE_CONTAINER, self::AZURE_ATTACHMENTS,
    ];

    /** Kept encrypted; a blank field on save leaves the saved one as it is. */
    public const SECRETS = [self::MS_SECRET, self::PUSHER_SECRET, self::CRON_TOKEN, self::WHATSAPP_TOKEN, self::AZURE_KEY];

    public static function get(string $key): string
    {
        try {
            $value = (string) Setting::get($key, '');
        } catch (Throwable) {
            return ''; // Not installed yet.
        }

        if ($value === '' || ! in_array($key, self::SECRETS, true)) {
            return $value;
        }

        try {
            return (string) decrypt($value);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function save(array $state): void
    {
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $state)) {
                continue;
            }

            $value = trim((string) $state[$key]);

            if (in_array($key, self::SECRETS, true)) {
                if ($value === '') {
                    continue;
                }

                $value = encrypt($value);
            }

            Setting::set($key, $value, 'integrations', 'string');
        }
    }

    /**
     * Clears one saved secret (its field can't be blanked, as a blank keeps it).
     */
    public static function forget(string $key): void
    {
        Setting::set($key, '', 'integrations', 'string');
    }

    /**
     * The saved services over the configuration — called once a request,
     * early, before anything sends or broadcasts.
     */
    public static function apply(): void
    {
        $ms = [self::get(self::MS_TENANT), self::get(self::MS_CLIENT), self::get(self::MS_SECRET)];

        if (! in_array('', $ms, true)) {
            [$tenant, $client, $secret] = $ms;

            config([
                'services.outlook.tenant_id' => $tenant,
                'services.outlook.client_id' => $client,
                'services.outlook.client_secret' => $secret,
                'mail.mailers.microsoft-graph.tenant_id' => $tenant,
                'mail.mailers.microsoft-graph.client_id' => $client,
                'mail.mailers.microsoft-graph.client_secret' => $secret,
            ]);

            if (($mailbox = self::get(self::MS_CALENDAR)) !== '') {
                config(['services.outlook.user_email' => $mailbox]);
            }
        }

        $pusher = [self::get(self::PUSHER_APP), self::get(self::PUSHER_KEY), self::get(self::PUSHER_SECRET)];

        if (! in_array('', $pusher, true)) {
            [$app, $key, $secret] = $pusher;
            $cluster = self::get(self::PUSHER_CLUSTER) ?: 'mt1';

            config([
                'broadcasting.default' => 'pusher',
                'broadcasting.connections.pusher.app_id' => $app,
                'broadcasting.connections.pusher.key' => $key,
                'broadcasting.connections.pusher.secret' => $secret,
                'broadcasting.connections.pusher.options.cluster' => $cluster,
                'broadcasting.connections.pusher.options.host' => 'api-'.$cluster.'.pusher.com',
                'broadcasting.connections.pusher.options.port' => 443,
                'broadcasting.connections.pusher.options.scheme' => 'https',
                'broadcasting.connections.pusher.options.useTLS' => true,
                'filament.broadcasting.echo' => [
                    'broadcaster' => 'pusher',
                    'key' => $key,
                    'cluster' => $cluster,
                    'forceTLS' => true,
                ],
            ]);
        }

        if (($token = self::get(self::CRON_TOKEN)) !== '') {
            config(['services.cron.token' => $token]);
        }

        // Nothing runs the queue without the cron link: emails and other
        // queued work go out at once instead of waiting for a worker.
        if (! self::cronOn() && config('queue.default') === 'database') {
            config(['queue.default' => 'sync']);
        }

        $whatsapp = [self::get(self::WHATSAPP_PHONE), self::get(self::WHATSAPP_TOKEN)];

        if (! in_array('', $whatsapp, true)) {
            config([
                'services.whatsapp.phone_id' => $whatsapp[0],
                'services.whatsapp.token' => $whatsapp[1],
            ]);
        }

        $azure = [self::get(self::AZURE_ACCOUNT), self::get(self::AZURE_KEY), self::get(self::AZURE_CONTAINER)];

        if (! in_array('', $azure, true)) {
            [$account, $key, $container] = $azure;

            $disk = [
                'driver' => 'azure-storage-blob',
                'credential' => 'shared_key',
                'account_name' => $account,
                'account_key' => $key,
                'container' => $container,
                // Private: a file is only ever opened through Wakeel, after its checks.
                'visibility' => 'private',
            ];

            config([
                'filesystems.disks.'.self::AZURE_DISK => $disk,
                'filesystems.disks.'.self::AZURE_PUBLIC_DISK => [...$disk, 'prefix' => 'public'],
            ]);

            // New attached files there; each one before keeps the storage it was saved in (Attachment::$disk).
            if (self::get(self::AZURE_ATTACHMENTS) === '1') {
                config(['attachments.disk' => self::AZURE_DISK]);
            }
        }
    }

    public static function azureOn(): bool
    {
        return is_array(config('filesystems.disks.'.self::AZURE_DISK));
    }

    /** Whether new files are kept in Azure ("Keep new files in Azure"). */
    public static function newFilesInAzure(): bool
    {
        return config('attachments.disk') === self::AZURE_DISK;
    }

    public static function microsoftOn(): bool
    {
        return filled(config('mail.mailers.microsoft-graph.tenant_id'))
            && filled(config('mail.mailers.microsoft-graph.client_id'))
            && filled(config('mail.mailers.microsoft-graph.client_secret'));
    }

    public static function pusherOn(): bool
    {
        return in_array(config('broadcasting.default'), ['pusher', 'reverb'], true) && filled(config('filament.broadcasting.echo'));
    }

    public static function cronOn(): bool
    {
        return strlen((string) config('services.cron.token')) >= 32;
    }

    public static function whatsappOn(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_id'));
    }

    public static function cronUrl(): ?string
    {
        return self::cronOn() ? route('cron.run', ['token' => config('services.cron.token')]) : null;
    }
}
