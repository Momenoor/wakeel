<?php

namespace Tests\Feature;

use App\Services\MMS\SentFolder;
use PHPUnit\Framework\TestCase;

/**
 * A cPanel mailbox keeps its sent mail in a folder a mail app named as it liked: the system finds it.
 */
class SentFolderTest extends TestCase
{
    /** @param  list<string|array{0: string, 1?: bool}>  $names */
    private function folders(array $names): array
    {
        return array_map(function ($entry): object {
            [$full, $noSelect] = [(string) (is_array($entry) ? $entry[0] : $entry), (bool) (is_array($entry) ? ($entry[1] ?? false) : false)];
            $delimiter = str_contains($full, '/') ? '/' : '.';

            return (object) ['full_name' => $full, 'name' => (string) collect(explode($delimiter, $full))->last(), 'no_select' => $noSelect, 'delimiter' => $delimiter];
        }, $names);
    }

    private function pick(array $names): ?string
    {
        return SentFolder::pick($this->folders($names))?->full_name;
    }

    public function test_cpanels_inbox_dot_sent_is_found(): void
    {
        $this->assertSame('INBOX.Sent', $this->pick(['INBOX', 'INBOX.Drafts', 'INBOX.Sent', 'INBOX.Trash', 'INBOX.spam']));
    }

    public function test_whatever_the_mail_app_called_it(): void
    {
        $this->assertSame('Sent Items', $this->pick(['INBOX', 'Drafts', 'Sent Items', 'Deleted Items', 'Junk Email']));
        $this->assertSame('INBOX.Sent Messages', $this->pick(['INBOX', 'INBOX.Sent Messages', 'INBOX.Drafts']));
        $this->assertSame('[Gmail]/Sent Mail', $this->pick(['INBOX', '[Gmail]/Drafts', '[Gmail]/Sent Mail', '[Gmail]/Trash']));
        $this->assertSame('INBOX.المرسلة', $this->pick(['INBOX', 'INBOX.المسودات', 'INBOX.المرسلة']));
        $this->assertSame('INBOX.Envoyés', $this->pick(['INBOX', 'INBOX.Envoyés', 'INBOX.Brouillons']));
        $this->assertSame('INBOX.Gesendet', $this->pick(['INBOX', 'INBOX.Gesendet', 'INBOX.Entwürfe']));
        $this->assertSame('INBOX.Sentmail', $this->pick(['INBOX', 'INBOX.Sentmail']));
    }

    public function test_the_plainest_name_wins_when_there_are_several(): void
    {
        $this->assertSame('INBOX.Sent', $this->pick(['INBOX.Sent Items', 'INBOX.Sent', 'INBOX.Sent Messages']));
        // A folder at the top of the mailbox before one deeper in.
        $this->assertSame('Sent', $this->pick(['INBOX.Archive.Sent', 'Sent']));
        // All of them, best first, for reading what was sent.
        $names = array_map(fn ($f) => $f->full_name, SentFolder::candidates($this->folders(['INBOX', 'Sent Items', 'INBOX.Sent', 'INBOX.Drafts'])));
        $this->assertSame(['INBOX.Sent', 'Sent Items'], $names);
    }

    public function test_never_drafts_trash_or_a_folder_that_cannot_be_opened(): void
    {
        $this->assertNull($this->pick(['INBOX', 'INBOX.Drafts', 'INBOX.Trash', 'INBOX.Unsent Messages', 'INBOX.Consent forms', 'INBOX.Outbox']));
        $this->assertNull($this->pick(['INBOX', ['Sent', true]]));
        $this->assertNull($this->pick([]));
    }
}
