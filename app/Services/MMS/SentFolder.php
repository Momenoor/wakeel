<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use Webklex\PHPIMAP\ClientManager;

/**
 * Copies a sent bulk mail into the sender mailbox's IMAP "Sent" folder, so
 * it shows up in Outlook like a message sent by hand.
 */
class SentFolder
{
    public function save(BulkMailCampaign $campaign, string $rawMessage): void
    {
        $client = (new ClientManager($this->config($campaign)))->account($campaign->from_sender_key);
        $client->connect();

        $client->getFolder('Sent')->appendMessage(
            $rawMessage,
            ['\Seen'],
            now()->format('d-M-Y h:i:s O')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function config(BulkMailCampaign $campaign): array
    {
        $sender = $campaign->sender_config;

        return [
            'accounts' => [
                $campaign->from_sender_key => [
                    'host' => $sender['host'],
                    'port' => 993,
                    'encryption' => $sender['encryption'],
                    'username' => $sender['username'],
                    'password' => $sender['password'],
                    'protocol' => 'imap',
                    'validate_cert' => true,
                    'authentication' => null,
                    'proxy' => [
                        'socket' => null,
                        'request_fulluri' => false,
                        'username' => null,
                        'password' => null,
                    ],
                    'timeout' => 30,
                    'extensions' => [],
                ],
            ],
        ];
    }
}
