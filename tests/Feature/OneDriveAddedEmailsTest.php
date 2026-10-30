<?php

namespace Tests\Feature;

use App\Enums\ProgressType;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFile;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\EmailFile;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveAddedEmails;
use App\Services\MMS\OneDriveClient;
use App\Services\MMS\ReceivedEmailFiling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\MemoryOneDrive;
use Tests\TestCase;

/**
 * Emails put in a matter's OneDrive folder by hand (.eml, .msg) — read and added to its emails, progress and
 * contacts, left where they were put with their PDF beside them; in the creditors' folder, their sender a creditor.
 */
class OneDriveAddedEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private MemoryOneDrive $oneDrive;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create(['email' => 'amr@wakeel-office.ae']));

        $this->oneDrive = new MemoryOneDrive('root-555');
        $this->app->instance(OneDriveClient::class, $this->oneDrive);

        $this->matter = Matter::factory()->create(['number' => '555', 'year' => '2026', 'type_id' => Type::factory()->create(['name' => 'اشهار إفلاس', 'has_creditors' => true])->id]);
        $this->folder($this->matter, 'root-555');

        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, '04 المراسلات', 'onedrive');
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS[ReceivedEmailFiling::CREDITOR], '07 مطالبات الدائنين/{{creditor}}', 'onedrive');
        Setting::clearCache();
    }

    private function folder(Matter $matter, string $root): MatterOneDriveFolder
    {
        return MatterOneDriveFolder::create([
            'matter_id' => $matter->id, 'folder_name' => $matter->number.'-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => $root,
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);
    }

    private static function eml(string $from, string $subject, string $id, string $to = 'court@dubai.gov.ae', string $cc = ''): string
    {
        return "From: {$from}\r\nTo: {$to}\r\n".($cc !== '' ? "Cc: {$cc}\r\n" : '')."Subject: {$subject}\r\nMessage-ID: <{$id}>\r\n"
            ."Date: Thu, 29 Oct 2026 10:00:00 +0400\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nPlease find our claim.\r\n";
    }

    public function test_an_email_put_in_the_folder_is_added_and_left_where_it_was_put(): void
    {
        $this->oneDrive->put('04 المراسلات/يدوي/Claim.eml', self::eml('Court Clerk <clerk@dubai.gov.ae>', 'Session notice', 'abc-1@dubai.gov.ae', 'court@dubai.gov.ae', 'judge@dubai.gov.ae'));

        $this->assertSame(['added' => 1, 'errors' => []], app(OneDriveAddedEmails::class)->readMatter($this->matter));

        $email = $this->matter->emails()->sole();
        $this->assertSame([MatterEmail::RECEIVED, MatterEmail::BY_ONEDRIVE, 'Session notice', 'abc-1@dubai.gov.ae'], [$email->direction, $email->matched_by, $email->subject, $email->message_id]);
        $this->assertSame(['04 المراسلات/يدوي', 'Claim.eml'], [$email->onedrive['folder'], $email->onedrive['added']]);
        $this->assertSame('2026-10-29 06:00', $email->at->utc()->format('Y-m-d H:i'));

        // Its PDF beside it, under its name; the file itself left as it was.
        $this->assertSame(['04 المراسلات/يدوي/Claim.eml', '04 المراسلات/يدوي/Claim.pdf'], $this->oneDrive->files());

        // A step in its progress, its contacts learned.
        $this->assertSame(ProgressType::EMAIL_RECEIVED, $this->matter->progress()->sole()->type);
        $this->assertEqualsCanonicalizing(['court@dubai.gov.ae', 'judge@dubai.gov.ae'], MatterContact::query()->where('owner_email', 'clerk@dubai.gov.ae')->pluck('email')->all());

        // Read again: looked at once — its own PDF and file not taken for another.
        $this->assertSame(0, app(OneDriveAddedEmails::class)->readMatter($this->matter)['added']);
        $this->assertSame(1, MatterEmail::count());
        $this->assertSame(MatterOneDriveFile::ADDED, MatterOneDriveFile::query()->sole()->outcome);
    }

    public function test_one_the_matter_has_and_those_the_system_saved_are_not_added(): void
    {
        // Saved by the system: its PDF and original — never downloaded.
        MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::FILED, 'sender_key' => 'iflas',
            'message_id' => 'known-1@x.ae', 'subject' => 'Claim', 'from' => 'a@x.ae', 'at' => now(),
            'files' => [['name' => 'Claim from A.pdf', 'path' => null]], 'onedrive' => ['pdf' => true, 'folder' => '04 المراسلات'],
        ]);
        $this->oneDrive->put('04 المراسلات/Claim from A.pdf');
        $this->oneDrive->put('04 المراسلات/Claim from A.eml', self::eml('a@x.ae', 'Claim', 'known-1@x.ae'));
        // The same email put elsewhere by hand: the matter has it.
        $this->oneDrive->put('99 أخرى/copy.eml', self::eml('a@x.ae', 'Claim', 'known-1@x.ae'));

        $this->assertSame(0, app(OneDriveAddedEmails::class)->readMatter($this->matter)['added']);

        $this->assertSame(1, MatterEmail::count());
        $this->assertSame([['99 أخرى/copy.eml', MatterOneDriveFile::KNOWN]], MatterOneDriveFile::query()->get()->map(fn ($file) => [$file->path, $file->outcome])->all());
    }

    public function test_in_the_creditors_folder_its_sender_is_made_a_creditor(): void
    {
        $this->oneDrive->put('07 مطالبات الدائنين/بنك دبي/مطالبة.eml', self::eml('claims@dib.ae', 'Claim', 'c-1@dib.ae'));
        $this->oneDrive->put('07 مطالبات الدائنين/claim.eml', self::eml('Emirates NBD <legal@enbd.ae>', 'Claim', 'c-2@enbd.ae'));

        $this->assertSame(2, app(OneDriveAddedEmails::class)->readMatter($this->matter)['added']);

        $this->assertEqualsCanonicalizing(['بنك دبي' => ['claims@dib.ae'], 'Emirates NBD' => ['legal@enbd.ae']], $this->matter->creditors()->with('addresses')->get()
            ->mapWithKeys(fn ($creditor) => [$creditor->name => $creditor->addresses->pluck('email')->all()])->all());

        // Left where they were put — not moved to their creditor's folder.
        $this->assertContains('07 مطالبات الدائنين/claim.eml', $this->oneDrive->files());
        $this->assertContains('07 مطالبات الدائنين/claim.pdf', $this->oneDrive->files());
        $this->assertSame(['', 'بنك دبي', null], [
            OneDriveAddedEmails::creditorFolderName($this->matter, '07 مطالبات الدائنين'),
            OneDriveAddedEmails::creditorFolderName($this->matter, '07 مطالبات الدائنين/بنك دبي/مرفقات'),
            OneDriveAddedEmails::creditorFolderName($this->matter, '04 المراسلات'),
        ]);
    }

    public function test_one_we_sent_is_added_as_sent(): void
    {
        $this->oneDrive->put('03 الصادر/notice.eml', self::eml('Amr <amr@wakeel-office.ae>', 'Notice', 's-1@wakeel-office.ae', 'info@mahad.ae', 'cfo@mahad.ae'));

        app(OneDriveAddedEmails::class)->readMatter($this->matter);

        $this->assertSame(MatterEmail::SENT, $this->matter->emails()->sole()->direction);
        $this->assertSame(ProgressType::EMAIL_SENT, $this->matter->progress()->sole()->type);
        $this->assertSame(['info@mahad.ae', 'cfo@mahad.ae'], MatterContact::query()->get(['owner_email', 'email'])->flatMap(fn ($c) => [$c->owner_email, $c->email])->all());
    }

    public function test_a_shared_main_folder_gives_a_file_to_the_matter_of_its_phase_folder(): void
    {
        $type = Type::factory()->create(['has_phases' => true, 'phases' => ['المرحلة الأولى', 'المرحلة الثانية'], 'onedrive_subfolders' => '{{matter.phase}}/01 المراسلات']);
        $this->matter->update(['type_id' => $type->id, 'phase' => 'المرحلة الأولى']);
        $second = Matter::factory()->create(['number' => '556', 'year' => '2026', 'type_id' => $type->id, 'phase' => 'المرحلة الثانية', 'final_report_at' => null]);
        $this->folder($second, 'root-555');

        $this->oneDrive->put('المرحلة الأولى/01 المراسلات/a.eml', self::eml('a@x.ae', 'First', 'p-1@x.ae'));
        $this->oneDrive->put('المرحلة الثانية/01 المراسلات/b.eml', self::eml('b@x.ae', 'Second', 'p-2@x.ae'));

        $this->assertSame(2, app(OneDriveAddedEmails::class)->readDue()['added']);

        $this->assertSame(['First'], $this->matter->emails()->pluck('subject')->all());
        $this->assertSame(['Second'], $second->emails()->pluck('subject')->all());
    }

    public function test_an_outlook_message_is_read(): void
    {
        $message = EmailFile::read('sample.msg', (string) file_get_contents(base_path('vendor/hfig/mapi/tests/_files/sample.msg')));

        $this->assertSame(['mlemos@acm.org', ['mlemos@linux.local'], '<20050430192829.0489.mlemos@acm.org>'], [$message['from'], $message['to'], $message['message_id']]);
        $this->assertStringContainsString('HTML message', $message['subject']);
        // Its file — not the pictures of its text.
        $this->assertSame(['attachment.txt'], array_column($message['attachments'], 'name'));
        $this->assertNull(EmailFile::read('broken.msg', 'not an Outlook message'));
    }
}
