<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;

/**
 * Copies a sent mail into the sender mailbox's IMAP "Sent" folder, so it
 * shows up in the mailbox like a message sent by hand.
 */
class SentFolder
{
    public function save(BulkMailCampaign $campaign, string $rawMessage): void
    {
        $this->saveFor($campaign->from_sender_key, $rawMessage);
    }

    /**
     * For any sender in config/mail_senders.php.
     */
    public function saveFor(string $senderKey, string $rawMessage): void
    {
        $sender = SenderMailer::sender($senderKey);

        // Microsoft 365 keeps the copy in Sent Items itself.
        if (SenderMailer::isMicrosoft($sender)) {
            return;
        }

        $this->folder($senderKey, $sender)->appendMessage(
            $rawMessage,
            ['\Seen'],
            now()->format('d-M-Y h:i:s O')
        );
    }

    /**
     * The sender mailbox's "Sent" folder over IMAP — to copy mail into, or
     * to read what was sent from it by hand (SentMailImporter).
     *
     * @param  array<string, mixed>|null  $sender
     */
    public function folder(string $senderKey, ?array $sender = null): Folder
    {
        $sender ??= SenderMailer::sender($senderKey);

        $client = (new ClientManager($this->config($senderKey, $sender)))->account($senderKey);
        $client->connect();

        return $client->getFolder('Sent');
    }

    /**
     * @param  array<string, mixed>  $sender
     * @return array<string, mixed>
     */
    private function config(string $senderKey, array $sender): array
    {
        return [
            'accounts' => [
                $senderKey => [
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
