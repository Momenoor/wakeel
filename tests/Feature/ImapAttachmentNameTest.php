<?php

namespace Tests\Feature;

use App\Services\MMS\SentFolder;
use App\Services\MMS\SentMailImporter;
use Tests\TestCase;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * A file's name in a cPanel mailbox's email read as it is: Outlook writes an Arabic one in Windows Arabic,
 * base64 — and base64 has "/" in it, which the mail library's own clean-up took out before decoding
 * ("=?windows-1256?B?5t/H4ck…?=" came out garbled, and OneDrive refused it).
 */
class ImapAttachmentNameTest extends TestCase
{
    public function test_an_encoded_name_with_a_slash_in_its_base64_reads_right(): void
    {
        $raw = "From: a@b.ae\r\nTo: c@d.ae\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b\"\r\n\r\n"
            ."--b\r\nContent-Type: text/plain\r\n\r\nhi\r\n"
            ."--b\r\nContent-Type: application/pdf;\r\n\tname=\"=?windows-1256?B?5t/H4ckg3cfO0ckgx+HR4+3L7S5wZGY=?=\"\r\n"
            ."Content-Disposition: attachment;\r\n\tfilename=\"=?windows-1256?B?5t/H4ckg3cfO0ckgx+HR4+3L7S5wZGY=?=\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-1.4')."\r\n--b--\r\n";

        // As the app reads its mailboxes (SentFolder's settings).
        $config = (new \ReflectionMethod(SentFolder::class, 'config'))->invoke(app(SentFolder::class), 'iflas', ['host' => 'mail.test', 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p']);
        $message = Message::fromString($raw, (new ClientManager($config))->getConfig());
        $attachment = $message->getAttachments()->first();

        $this->assertSame('وكالة فاخرة الرميثي.pdf', SentMailImporter::decodeHeader((string) ($attachment->name ?: $attachment->filename)));
    }
}
