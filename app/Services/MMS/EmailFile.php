<?php

namespace App\Services\MMS;

use Hfig\MAPI\MapiMessageFactory;
use Hfig\MAPI\Message\Message;
use Hfig\MAPI\OLE\Pear\DocumentFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An email saved as a file — dragged out of Outlook (.msg) or another mail program (.eml) — read as the collector
 * reads one from a mailbox: who, when, its text and its files.
 */
class EmailFile
{
    public const EXTENSIONS = ['eml', 'msg'];

    public static function isEmail(string $name): bool
    {
        return in_array(mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * The email in the file, by its kind — null when it cannot be read.
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $name, string $contents): ?array
    {
        return mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'msg' ? self::fromMsg($contents) : ForwardedEmail::fromRaw($contents);
    }

    /**
     * An Outlook message (.msg): its sender's own address — an Exchange one's from the headers it came with.
     *
     * @return array<string, mixed>|null
     */
    public static function fromMsg(string $contents): ?array
    {
        try {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $contents);
            rewind($stream);
            $message = (new MapiMessageFactory)->parseMessage((new DocumentFactory)->createFromStream($stream));
        } catch (Throwable $e) {
            Log::info('Outlook message not read: '.$e->getMessage());

            return null;
        }

        $properties = $message->properties;
        $headers = (string) ($properties['transport_message_headers'] ?? '');
        [$fromName, $from] = self::sender($message, $headers);
        $html = self::html($message);
        $recipients = fn (string $type): array => array_values(array_filter(array_map(
            fn ($recipient): string => self::address((string) $recipient->getEmail()),
            $message->getRecipientsOfType($type),
        )));
        $at = $properties['message_delivery_time'] ?? $properties['client_submit_time'] ?? null;

        return [
            'message_id' => (string) ($message->getInternetMessageId() ?: self::header($headers, 'message-id')),
            'subject' => (string) ($properties['subject'] ?? ''),
            'from' => $from,
            'from_name' => $fromName,
            'to' => $recipients('To'),
            'cc' => $recipients('Cc'),
            'at' => is_numeric($at) && (int) $at > 0 ? date(DATE_ATOM, (int) $at) : null,
            'body' => $html,
            'attachments' => self::files($message, $html),
            'raw' => null,
            'exact' => true,
        ];
    }

    /** @return array{0: string, 1: string} its name and address */
    private static function sender(Message $message, string $headers): array
    {
        $properties = $message->properties;
        $name = trim((string) ($properties['sender_name'] ?? ''));

        foreach ([$properties['sender_smtp_address'] ?? null, $properties['sent_representing_smtp_address'] ?? null, $properties['sender_email_address'] ?? null] as $candidate) {
            if (($address = self::address((string) $candidate)) !== '') {
                return [$name, $address];
            }
        }

        // An Exchange sender (EX:/o=…): the From of the headers it came with, when it came from outside.
        $header = self::header($headers, 'from');

        return [$name, preg_match('/[^\s<>"]+@[^\s<>"]+/u', $header, $m) ? mb_strtolower($m[0]) : ''];
    }

    /** An email address, or nothing — an Exchange one (EX:/o=…) is none. */
    private static function address(string $value): string
    {
        $value = trim($value, " <>\t\r\n'\"");

        return str_contains($value, '@') && ! str_contains($value, '/') ? mb_strtolower($value) : '';
    }

    private static function header(string $headers, string $name): string
    {
        return preg_match('/^'.preg_quote($name, '/').':\s*(.*(?:\r?\n[ \t].*)*)/im', $headers, $m) ? trim((string) preg_replace('/\r?\n[ \t]+/', ' ', $m[1])) : '';
    }

    /** Its text as HTML — the plain text's, when it has no HTML; nothing when it has neither (RTF only). */
    private static function html(Message $message): string
    {
        $html = rescue(fn () => (string) $message->getBodyHTML(), '', report: false);

        if (trim($html) !== '') {
            return $html;
        }

        return nl2br(e((string) rescue(fn () => (string) $message->getBody(), '', report: false)));
    }

    /**
     * The files it came with — not the pictures of its text (a signature's logo), nor an email attached inside it.
     *
     * @return list<array{name: string, contents: string, mime: string}>
     */
    private static function files(Message $message, string $html): array
    {
        $files = [];

        foreach ($message->getAttachments() as $attachment) {
            $contents = $attachment->getData();
            $name = trim((string) $attachment->getFilename()) ?: 'attachment';
            $mime = (string) $attachment->getMimeType();

            if (! is_string($contents) || MatterReplyCollector::isPartOfText($name, $mime, (string) $attachment->getContentId(), strlen($contents), false, $html)) {
                continue;
            }

            $files[] = ['name' => $name, 'contents' => $contents, 'mime' => $mime];
        }

        return $files;
    }
}
