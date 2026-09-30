<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use Webklex\PHPIMAP\Client;
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
     * The sender mailbox's "Sent" folder over IMAP, where sent mail is
     * copied to.
     *
     * @param  array<string, mixed>|null  $sender
     */
    public function folder(string $senderKey, ?array $sender = null): Folder
    {
        return $this->client($senderKey, $sender)->getFolder('Sent');
    }

    /**
     * Every folder in the mailbox that may hold sent mail — a mail app picks
     * its own: "Sent", "Sent Items" (Outlook), "Sent Messages", "INBOX.Sent"
     * on cPanel, or a translated name.
     *
     * @return list<Folder>
     */
    public function sentFolders(string $senderKey): array
    {
        $folders = [];

        foreach ($this->client($senderKey)->getFolders(false) as $folder) {
            if (! $folder->no_select && preg_match(self::SENT_NAMES, $folder->full_name.' '.$folder->name)) {
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    private const SENT_NAMES = '~sent|المرسل|المُرسل|envoy|gesendet|enviad|inviat|verzonden~iu';

    /**
     * @param  array<string, mixed>|null  $sender
     */
    private function client(string $senderKey, ?array $sender = null): Client
    {
        $sender ??= SenderMailer::sender($senderKey);

        $client = (new ClientManager($this->config($senderKey, $sender)))->account($senderKey);
        $client->connect();

        return $client;
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
