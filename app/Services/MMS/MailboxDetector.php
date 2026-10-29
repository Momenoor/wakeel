<?php

namespace App\Services\MMS;

use App\Models\MailSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Works out where a mailbox lives — Microsoft 365 or a cPanel (SMTP)
 * server — and the settings to send from it.
 *
 * A domain can be split: jpaemirates.com receives its mail at Microsoft
 * while iflas@, haikala@ and easar@ live on cPanel. So the domain alone
 * can't tell; the mailbox itself is looked up in Microsoft 365 first
 * (Microsoft Graph, needs the app registration's User.Read.All
 * permission), and only when that's not possible does the domain's MX
 * record decide — reported as a guess.
 */
class MailboxDetector
{
    public const CERTAIN = 'certain';

    public const GUESS = 'guess';

    /**
     * Public mail services: their domains, where they send from and where their inbox is read — another server
     * than the one sent through, unlike cPanel's (imap()). Each signed in to with an app password, not the
     * account's own.
     */
    public const PROVIDERS = [
        'Yahoo' => [
            'domains' => '/^(yahoo\.[a-z.]+|ymail\.com|rocketmail\.com)$/',
            'host' => 'smtp.mail.yahoo.com',
            'imap' => 'imap.mail.yahoo.com',
        ],
        'Gmail' => [
            'domains' => '/^(gmail\.com|googlemail\.com)$/',
            'host' => 'smtp.gmail.com',
            'imap' => 'imap.gmail.com',
        ],
    ];

    /**
     * Where a mailbox sent through SMTP is read (IMAP, port 993): a public service's own inbox server, over SSL;
     * any other (cPanel) the server it sends through.
     *
     * @param  array<string, mixed>  $sender
     * @return array{host: string, encryption: string}
     */
    public static function imap(array $sender): array
    {
        $host = Str::lower(trim((string) ($sender['host'] ?? '')));

        foreach (self::PROVIDERS as $provider) {
            if ($provider['host'] === $host) {
                return ['host' => $provider['imap'], 'encryption' => 'ssl'];
            }
        }

        return ['host' => (string) $sender['host'], 'encryption' => (string) $sender['encryption']];
    }

    /**
     * @return array{driver: string, confidence: string, note: string, host?: string, port?: int, encryption?: string, username?: string}
     */
    public function detect(string $address): array
    {
        $address = trim(Str::lower($address));
        $domain = Str::after($address, '@');

        // Yahoo, Gmail: known — neither Microsoft 365 nor cPanel.
        foreach (self::PROVIDERS as $name => $provider) {
            if (preg_match($provider['domains'], $domain) === 1) {
                return [
                    'driver' => MailSender::SMTP,
                    'confidence' => self::CERTAIN,
                    'note' => __(':provider mailbox: sent through :host and its inbox read at :imap. Enter an app password made for it in the :provider account\'s security settings — the account\'s own password is refused.', ['provider' => $name, 'host' => $provider['host'], 'imap' => $provider['imap']]),
                    'host' => $provider['host'],
                    'port' => 465,
                    'encryption' => 'ssl',
                ];
            }
        }

        $inMicrosoft = $this->existsInMicrosoft365($address);

        if ($inMicrosoft === true) {
            return [
                'driver' => MailSender::MICROSOFT,
                'confidence' => self::CERTAIN,
                'note' => __('Found in your Microsoft 365: sent through Microsoft Graph.'),
            ];
        }

        if ($inMicrosoft === false) {
            return $this->cpanel($domain, self::CERTAIN, __('Not a Microsoft 365 mailbox: set up as a cPanel mailbox on :host.', ['host' => 'mail.'.$domain]));
        }

        // Microsoft couldn't be asked: go by where the domain's mail goes.
        $mx = $this->mxHosts($domain);

        if (collect($mx)->contains(fn ($host) => str_ends_with(Str::lower($host), '.mail.protection.outlook.com'))) {
            return [
                'driver' => MailSender::MICROSOFT,
                'confidence' => self::GUESS,
                'note' => __('Probably Microsoft 365 — :domain receives its mail at Microsoft. If this mailbox is on cPanel instead, choose cPanel.', ['domain' => $domain]),
            ];
        }

        return $this->cpanel($domain, self::GUESS, __('Probably a cPanel mailbox (:domain\'s mail isn\'t at Microsoft). Check the server and enter the password.', ['domain' => $domain]));
    }

    /**
     * @return array{driver: string, confidence: string, note: string, host: string, port: int, encryption: string}
     */
    private function cpanel(string $domain, string $confidence, string $note): array
    {
        return [
            'driver' => MailSender::SMTP,
            'confidence' => $confidence,
            'note' => $note,
            'host' => 'mail.'.$domain,
            'port' => 587,
            'encryption' => 'tls',
        ];
    }

    /**
     * true: a mailbox in the tenant; false: not in it; null: couldn't ask
     * (Graph not set up, no permission, no connection).
     */
    public function existsInMicrosoft365(string $address): ?bool
    {
        $config = config('mail.mailers.microsoft-graph');

        if (blank($config['tenant_id'] ?? null) || blank($config['client_id'] ?? null) || blank($config['client_secret'] ?? null)) {
            return null;
        }

        try {
            $token = Http::asForm()->timeout(8)
                ->post('https://login.microsoftonline.com/'.$config['tenant_id'].'/oauth2/v2.0/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                ])->json('access_token');

            if (blank($token)) {
                return null;
            }

            $response = Http::withToken($token)->timeout(8)
                ->get('https://graph.microsoft.com/v1.0/users/'.rawurlencode($address), ['$select' => 'id,mail']);

            return match (true) {
                $response->successful() => true,
                $response->status() === 404 => false,
                default => null, // 403: no User.Read.All — ask the MX record instead.
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    protected function mxHosts(string $domain): array
    {
        try {
            return array_column(dns_get_record($domain, DNS_MX) ?: [], 'target');
        } catch (Throwable) {
            return [];
        }
    }
}
