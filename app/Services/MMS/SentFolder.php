<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use App\Support\Tolerant;
use RuntimeException;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

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
     * For any mail sender.
     */
    public function saveFor(string $senderKey, string $rawMessage): void
    {
        $sender = SenderMailer::sender($senderKey);

        // Microsoft 365 keeps the copy in Sent Items itself.
        if (SenderMailer::isMicrosoft($sender)) {
            return;
        }

        $client = $this->client($senderKey, $sender);

        $this->sentFolderOf($client)->appendMessage(
            $rawMessage,
            ['\Seen'],
            now()->format('d-M-Y H:i:s O')
        );
    }

    /**
     * The sender mailbox's sent folder over IMAP, where sent mail is copied
     * to: whichever of its folders is the sent one, whatever the mailbox calls
     * it (see pick()).
     *
     * @param  array<string, mixed>|null  $sender
     */
    public function folder(string $senderKey, ?array $sender = null): Folder
    {
        return $this->sentFolderOf($this->client($senderKey, $sender));
    }

    /**
     * The mailbox's sent folder: the best of the folders it has, else one made —
     * "Sent", under INBOX when the mailbox keeps its folders there (cPanel's
     * Dovecot: INBOX.Sent).
     */
    private function sentFolderOf(Client $client): Folder
    {
        $folders = $client->getFolders(false)->all();

        if ($found = self::pick($folders)) {
            return $found;
        }

        $inside = collect($folders)->contains(fn ($folder) => preg_match('~^INBOX[./]~i', (string) $folder->full_name));
        $delimiter = (string) (collect($folders)->first(fn ($folder) => (string) $folder->delimiter !== '')?->delimiter ?? '.');

        return $client->createFolder($inside ? 'INBOX'.$delimiter.'Sent' : 'Sent');
    }

    /**
     * The folder sent mail belongs in, among a mailbox's folders — those a mail
     * app may have named "Sent", "Sent Items" (Outlook), "Sent Messages" (Apple),
     * "Sent Mail", "INBOX.Sent" on cPanel, a translated name (المرسلة, Envoyés,
     * Gesendet …). The plainest name first; never Drafts, Trash, Junk. Null when
     * there is none.
     *
     * @param  iterable<object>  $folders  each with full_name, name and no_select
     */
    public static function pick(iterable $folders): ?object
    {
        return collect(self::candidates($folders))->first();
    }

    /**
     * Every folder that may hold sent mail, the best first.
     *
     * @param  iterable<object>  $folders
     * @return list<object>
     */
    public static function candidates(iterable $folders): array
    {
        $plain = ['sent', 'sent items', 'sent messages', 'sent mail', 'sentmail', 'sent-mail', 'sent_items', 'المرسلة', 'المرسل', 'العناصر المرسلة', 'الرسائل المرسلة', 'الصادرة', 'الصادر', 'البريد الصادر', 'envoyés', 'envoyes', 'éléments envoyés', 'gesendet', 'gesendete elemente', 'enviados', 'elementos enviados', 'inviati', 'posta inviata', 'verzonden', 'verzonden items'];

        return collect($folders)
            ->filter(fn ($folder) => ! ($folder->no_select ?? false))
            ->map(function ($folder) use ($plain): ?array {
                $name = mb_strtolower(trim((string) $folder->name));
                $full = mb_strtolower((string) $folder->full_name);

                // Not a place for sent mail, whatever else its name says.
                if (preg_match(self::NOT_SENT, $full.' '.$name)) {
                    return null;
                }

                $rank = in_array($name, $plain, true) ? array_search($name, $plain, true) : (preg_match(self::SENT_NAMES, $full.' '.$name) ? 100 : null);

                return $rank === null ? null : ['folder' => $folder, 'rank' => (int) $rank, 'depth' => substr_count($full, '.') + substr_count($full, '/')];
            })
            ->filter()
            // Plainest name first; at the top of the mailbox (or under INBOX) before deeper in.
            ->sortBy([['rank', 'asc'], ['depth', 'asc']])
            ->pluck('folder')
            ->values()
            ->all();
    }

    /**
     * The sender mailbox's inbox over IMAP, where replies arrive — or the folder it is set to be read from
     * (Mail senders): one its own filter moves the emails it picks into (Yahoo's filters cannot forward).
     */
    public function inbox(string $senderKey, ?array $sender = null): Folder
    {
        $sender ??= SenderMailer::sender($senderKey);

        return $this->folderNamed($senderKey, (string) ($sender['folder'] ?? ''), $sender);
    }

    /**
     * A folder of the mailbox by its name or path (named()) — empty or "INBOX": the Inbox. Not there: said so.
     *
     * @param  array<string, mixed>|null  $sender
     */
    public function folderNamed(string $senderKey, string $name, ?array $sender = null): Folder
    {
        $sender ??= SenderMailer::sender($senderKey);
        $client = $this->client($senderKey, $sender);
        $name = trim($name);

        if ($name === '' || strcasecmp($name, 'INBOX') === 0) {
            return $client->getFolder('INBOX');
        }

        return self::named($client->getFolders(false)->all(), $name)
            ?? throw new RuntimeException(__('The folder ":folder" is not in the mailbox :address.', ['folder' => $name, 'address' => (string) ($sender['address'] ?? $senderKey)]));
    }

    /**
     * Every folder of the mailbox that holds emails, by its whole path ("INBOX", "Wakeel", "INBOX.Courts") — to
     * choose from (Settings → Mailbox folders).
     *
     * @return list<string>
     */
    public function folders(string $senderKey): array
    {
        return collect($this->client($senderKey)->getFolders(false)->all())
            ->reject(fn ($folder) => $folder->no_select ?? false)
            ->map(fn ($folder): string => (string) $folder->full_name)
            ->values()
            ->all();
    }

    /**
     * A folder's emails after the one given (by its IMAP UID): the oldest first — or the newest, for showing it —
     * at most as many as asked. Each as a plain array: uid, message_id, subject, from, from_name, to, cc, at,
     * seen; with $whole, html and raw (the email as it came) too.
     *
     * @return list<array<string, mixed>>
     */
    public function emails(string $senderKey, string $folder, int $afterUid = 0, int $limit = 20, bool $newestFirst = false, bool $whole = true): array
    {
        $query = $this->folderNamed($senderKey, $folder)->query()->leaveUnread()->setFetchBody($whole)->setFetchFlags(true)->limit($limit);
        $query = $newestFirst ? $query->setFetchOrderDesc() : $query->setFetchOrderAsc();

        // Decoded tolerantly: a badly encoded part must not lose the folder.
        return Tolerant::run(fn () => $query->getByUidGreater($afterUid))
            ->map(fn (Message $message): array => self::plain($message, $whole))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function plain(Message $message, bool $whole): array
    {
        $addresses = fn (string $header) => collect($message->get($header)?->all() ?? [])->filter(fn ($address) => $address instanceof Address)->values();
        $from = $addresses('from')->first();
        $date = $message->getDate()?->toDate();

        return [
            'uid' => (int) $message->uid,
            'message_id' => trim((string) $message->getMessageId(), "<> \t"),
            'subject' => SentMailImporter::decodeHeader((string) $message->getSubject()),
            'from' => (string) ($from?->mail ?? ''),
            'from_name' => SentMailImporter::decodeHeader((string) ($from?->personal ?? '')),
            'to' => $addresses('to')->map(fn (Address $address): string => (string) $address->mail)->all(),
            'cc' => $addresses('cc')->map(fn (Address $address): string => (string) $address->mail)->all(),
            // An unreadable Date header comes back as 1970: none.
            'at' => $date !== null && $date->year >= 1971 ? $date->toIso8601String() : null,
            'seen' => $message->hasFlag('seen'),
            'html' => $whole ? ($message->hasHTMLBody() ? (string) $message->getHTMLBody() : nl2br(e((string) $message->getTextBody()))) : '',
            // The email as it came: its header, a blank line, its body.
            'raw' => $whole ? rtrim((string) $message->getHeader()?->raw, "\r\n")."\r\n\r\n".$message->getRawBody() : '',
        ];
    }

    /**
     * A mailbox's folder by the name given it: its whole path ("INBOX.Wakeel", "Courts/Wakeel" — whichever
     * separator the mailbox uses) or, failing that, its own name; letter case aside. Null when there is none.
     *
     * @param  iterable<object>  $folders  each with full_name, name and no_select
     */
    public static function named(iterable $folders, string $name): ?object
    {
        $plain = fn (string $path): string => mb_strtolower(trim((string) preg_replace('~[./\\\\]+~', '/', trim($path)), '/'));
        $wanted = $plain($name);
        $folders = collect($folders)->reject(fn ($folder) => $folder->no_select ?? false);

        return $folders->first(fn ($folder) => $plain((string) $folder->full_name) === $wanted)
            ?? $folders->first(fn ($folder) => $plain((string) $folder->name) === $wanted);
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
        return self::candidates($this->client($senderKey)->getFolders(false)->all());
    }

    /** Folders that are never the sent one. */
    private const NOT_SENT = '~draft|trash|junk|spam|deleted|unsent|outbox|المسودات|المحذوفات|المهملات|الوارد~iu';

    private const SENT_NAMES = '~\bsent|المرسل|المُرسل|envoy|gesendet|enviad|inviat|verzonden~iu';

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
        $imap = MailboxDetector::imap($sender);

        return [
            // A message whose Date header cannot be read (spam often has one) gets a date of its own
            // instead of failing the whole fetch — and so the whole mailbox.
            'options' => [
                'soft_fail' => true,
                'fallback_date' => '1970-01-01 00:00:00',
            ],
            // Attachment names as they came: the library's clean-up takes "/" out before it has decoded
            // the name, and "/" is a letter of an encoded one's base64 — "=?windows-1256?B?5t/H4ck…?=" came
            // out garbled and OneDrive refused it. The name is decoded and cleaned here instead
            // (SentMailImporter::decodeHeader(), SentEmailArchive::cleanBase()); no file is put by it.
            'security' => [
                'detect_spoofing' => true,
                'detect_spoofing_exception' => false,
                'sanitize_filenames' => false,
            ],
            'accounts' => [
                $senderKey => [
                    'host' => $imap['host'],
                    'port' => 993,
                    'encryption' => $imap['encryption'],
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
