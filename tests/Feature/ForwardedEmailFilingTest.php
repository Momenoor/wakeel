<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Filament\Mms\Pages\UnfiledEmails;
use App\Models\EmailForward;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\ForwardedEmail;
use App\Services\MMS\ForwardedEmailFiler;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\Support\MailSenders;
use Tests\TestCase;

/**
 * The filing mailbox: an email whose matter could not be told, forwarded to it by someone in the office naming
 * its matter, is filed on that matter as it came from its own sender — one already waiting in Unfiled emails
 * filed itself; never from a stranger, nor from someone using a colleague's address, nor on a matter the
 * forwarder may not work on.
 */
class ForwardedEmailFilingTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Matter $first;

    private Matter $second;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();
        MailSenders::add('iflas', ['address' => 'iflas@jpa.ae', 'name' => 'JPA']);
        // In the office's Microsoft 365: an email from inside it is told by Exchange's own header.
        MailSenders::add('files', ['address' => 'files@jpa.ae', 'name' => 'Filing', 'files_forwards' => true, 'driver' => MailSender::MICROSOFT]);
        config(['services.outlook' => ['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Http::fake(fn (Request $request) => str_contains($request->url(), 'login.microsoftonline.com')
            ? Http::response(['access_token' => 'token'])
            : Http::response(['id' => 'folder-id', 'webUrl' => 'https://od/x'], 201));

        $this->me = User::factory()->create(['email' => 'Momen@jpa.ae']);
        // Whoever forwards may work on every matter; anyone else only as their own permissions say.
        Gate::before(fn (User $user) => $user->is($this->me) ? true : null);
        $this->actingAs($this->me);

        $this->first = $this->matter('986');
        $this->second = $this->matter('987');
    }

    private function matter(string $number): Matter
    {
        $matter = Matter::factory()->create(['number' => $number, 'year' => '2026']);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => Party::factory()->create()->id, 'role' => 'party', 'type' => 'defendant']);
        MatterOneDriveFolder::create(['matter_id' => $matter->id, 'folder_name' => $number.'-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-'.$number,
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id]);

        return $matter;
    }

    /** An Outlook forward (inline): the forwarder's words, then the original quoted under its header. */
    private function forward(array $attributes = [], string $note = '<p>986/2026</p><p>Momen</p>', ?string $quoted = null): array
    {
        $quoted ??= '<hr style="display:inline-block;width:98%"><div id="divRplyFwdMsg" dir="ltr"><font face="Calibri, sans-serif"><b>From:</b> Office Al Banna &lt;office@albanna.ae&gt;<br>'
            .'<b>Sent:</b> Thursday, October 22, 2026 9:15 AM<br><b>To:</b> JPA &lt;iflas@jpa.ae&gt;<br><b>Subject:</b> مستندات الدعوى</font><div>&nbsp;</div></div>'
            .'<div><p>مرفق المستندات المطلوبة.</p></div>';

        return [
            'message_id' => '<fw-'.uniqid().'@jpa.ae>', 'references' => [], 'subject' => 'FW: مستندات الدعوى', 'from' => 'momen@jpa.ae', 'from_name' => 'Momen',
            'to' => ['files@jpa.ae'], 'cc' => [], 'at' => '2026-10-24T10:00:00+04:00', 'body' => $note.$quoted,
            'attachments' => [['name' => 'عقد.pdf', 'contents' => '%PDF-contract', 'mime' => 'application/pdf']],
            // Sent from inside the office's Microsoft 365.
            'headers' => ['x-ms-exchange-organization-authas' => 'Internal'],
            ...$attributes,
        ];
    }

    private function read(array ...$messages): void
    {
        app(MatterReplyCollector::class)->handle($messages, collect(), 'files');
    }

    private function told(User $user): ?string
    {
        return $user->notifications()->where('data->viewData->'.NotificationType::DATA_KEY, NotificationType::MATTER_EMAIL_FORWARDED->value)->latest()->first()?->data['title'] ?? null;
    }

    public function test_an_email_forwarded_naming_its_matter_is_filed_as_it_came_from_its_sender(): void
    {
        $this->read($forward = $this->forward());

        $email = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        // The original's: its sender, subject, time — not the forward's.
        $this->assertSame([$this->first->id, MatterEmail::FILED, MatterEmail::BY_FORWARD], [$email->matter_id, $email->status, $email->matched_by]);
        $this->assertSame('Office Al Banna <office@albanna.ae>', $email->from);
        $this->assertSame('مستندات الدعوى', $email->subject);
        $this->assertSame('2026-10-22 09:15', $email->at->format('Y-m-d H:i'));
        // The PDF and the file the forward carried, on the matter.
        $this->assertContains('عقد.pdf', array_column((array) $email->files, 'name'));
        $this->assertSame(1, $this->first->progress()->count());

        // Read once: the same forward again changes nothing.
        $this->read($forward);
        $this->assertSame(1, MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->count());
        $this->assertSame([ForwardedEmailFiler::FILED, $this->me->id, $email->id], [EmailForward::sole()->outcome, EmailForward::sole()->user_id, EmailForward::sole()->matter_email_id]);
        $this->assertNull($this->told($this->me));
    }

    public function test_from_arabic_outlook_with_the_matter_in_the_address(): void
    {
        $quoted = '<hr><div id="divRplyFwdMsg" dir="rtl"><b>من:</b> مكتب البنا &lt;office@albanna.ae&gt;<br><b>تاريخ الإرسال:</b> الخميس، 22 أكتوبر، 2026 09:15 م<br>'
            .'<b>إلى:</b> JPA &lt;iflas@jpa.ae&gt;<br><b>الموضوع:</b> مستندات 900/2025</div><div><p>مرفق.</p></div>';

        // No number in the forwarder's words; the original's own (900/2025) is not theirs.
        $this->read($this->forward(['to' => ['Files+987-2026@jpa.ae'], 'subject' => 'إعادة توجيه: مستندات 900/2025'], '<p>للأرشفة</p>', $quoted));

        $email = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $this->assertSame($this->second->id, $email->matter_id);
        $this->assertSame('مكتب البنا <office@albanna.ae>', $email->from);
        $this->assertSame('2026-10-22 21:15', $email->at->format('Y-m-d H:i'));
    }

    public function test_forwarded_as_an_attachment_the_email_itself_is_kept(): void
    {
        $original = "From: Office <office@albanna.ae>\r\nTo: iflas@jpa.ae\r\nSubject: Contract\r\nDate: Thu, 22 Oct 2026 09:15:00 +0400\r\nMessage-ID: <orig-1@albanna.ae>\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b2\"\r\n\r\n--b2\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Please find the contract.</p>\r\n"
            ."--b2\r\nContent-Type: application/pdf; name=\"contract.pdf\"\r\nContent-Disposition: attachment; filename=\"contract.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi1jb250cmFjdA==\r\n--b2--\r\n";
        $raw = "From: momen@jpa.ae\r\nTo: files@jpa.ae\r\nSubject: FW: Contract\r\nMessage-ID: <fw-att@jpa.ae>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n986/2026\r\n--b1\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename=\"original.eml\"\r\n\r\n".$original."--b1--\r\n";

        $this->assertSame(str_replace("\r\n", "\n", $original), ForwardedEmail::attachedEmail($raw));

        $this->read($this->forward(['body' => '<p>986/2026</p>', 'attachments' => [], 'raw' => fn (): string => $raw]));

        $email = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $this->assertSame(['orig-1@albanna.ae', 'Office <office@albanna.ae>', 'Contract', $this->first->id], [$email->message_id, $email->from, $email->subject, $email->matter_id]);
        $this->assertContains('contract.pdf', array_column((array) $email->files, 'name'));
    }

    public function test_one_waiting_in_unfiled_emails_is_filed_itself_and_leaves_the_list(): void
    {
        Storage::disk('public')->put('email-unfiled/1/email.pdf', '%PDF');
        $waiting = MatterEmail::create(['direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::PENDING, 'sender_key' => 'iflas', 'message_id' => 'w-1@albanna.ae',
            'subject' => 'مستندات الدعوى', 'from' => 'Office Al Banna <office@albanna.ae>', 'at' => '2026-10-22 09:16:00', 'files' => [['name' => 'email.pdf', 'path' => 'email-unfiled/1/email.pdf']]]);

        $this->read($this->forward());

        $this->assertSame([MatterEmail::FILED, $this->first->id], [$waiting->fresh()->status, $waiting->fresh()->matter_id]);
        $this->assertSame(1, MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->count());
        $this->assertSame(ForwardedEmailFiler::FILED_WAITING, EmailForward::sole()->outcome);
        Livewire::test(UnfiledEmails::class)->assertCanNotSeeTableRecords([$waiting]);
    }

    public function test_naming_more_than_one_matter_it_waits_in_unfiled_emails_for_them_to_choose(): void
    {
        $this->me->givePermissionTo(Permission::findOrCreate('View:UnfiledEmails'));

        $this->read($this->forward([], '<p>986/2026 أو 987/2026</p>'));

        // As it came from its own sender, waiting — those matters its candidates.
        $email = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $this->assertSame([MatterEmail::PENDING, null, 'Office Al Banna <office@albanna.ae>'], [$email->status, $email->matter_id, $email->from]);
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], array_map('intval', (array) $email->candidates));
        $this->assertSame(ForwardedEmailFiler::HELD, EmailForward::sole()->outcome);
        $this->assertStringContainsString('more than one matter', (string) $this->told($this->me));
        Livewire::test(UnfiledEmails::class)->assertCanSeeTableRecords([$email]);

        // Waiting already: asked about these matters instead — never a second copy.
        $email->update(['candidates' => [$this->first->id]]);
        $this->read($this->forward([], '<p>986/2026 و 987/2026</p>'));
        $this->assertSame(1, MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->count());
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], array_map('intval', (array) $email->fresh()->candidates));
    }

    public function test_the_style_sheet_outlook_puts_above_the_note_is_not_read_and_a_number_no_matter_has_is_named(): void
    {
        // Outlook's style sheet, a number in it: not the forwarder's words — 986/2026 below is.
        $this->read($this->forward([], '<html><head><style>P {margin-top:0;} /* 987/2026 */</style></head><body><!-- 987/2026 --><p>986/2026</p></body>'));
        $this->assertSame($this->first->id, MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole()->matter_id);

        // A number read, but no matter has it: the forwarder told which.
        $this->read($this->forward([], '<p>555/2026</p>'));
        $this->assertSame(ForwardedEmailFiler::NO_MATTER, EmailForward::query()->latest('id')->first()->outcome);
        $this->assertStringContainsString('555/2026 is not a matter', (string) $this->told($this->me));
    }

    public function test_nothing_is_filed_from_a_stranger_from_someone_using_an_address_or_on_a_matter_not_theirs(): void
    {
        $colleague = User::factory()->create(['email' => 'sara@jpa.ae']);

        $this->read(
            // Not anyone in the office: nobody's instruction, nobody told.
            $this->forward(['from' => 'someone@gmail.com']),
            // An office address, but not sent from inside the office.
            $this->forward(['headers' => ['authentication-results' => 'spf=fail; dkim=none; dmarc=fail']]),
            // No matter named.
            $this->forward([], '<p>للأرشفة</p>'),
        );

        $this->assertSame(0, MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->count());
        $this->assertEqualsCanonicalizing([ForwardedEmailFiler::NOT_A_USER, ForwardedEmailFiler::UNVERIFIED, ForwardedEmailFiler::NO_MATTER], EmailForward::pluck('outcome')->all());
        $this->assertSame(2, $this->me->notifications()->count());
        $this->assertTrue($this->me->notifications()->get()->contains(fn ($notification): bool => str_contains((string) ($notification->data['title'] ?? ''), 'no matter')));

        // A colleague on a matter they may not work on.
        $this->read($this->forward(['from' => 'sara@jpa.ae', 'headers' => ['authentication-results' => 'mx.jpa.ae; spf=pass smtp.mailfrom=jpa.ae; dkim=pass']]));
        $this->assertSame(0, MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->count());
        $this->assertStringContainsString('986/2026', (string) $this->told($colleague));
    }

    public function test_who_sent_a_forward_is_read_only_from_what_the_receiving_server_wrote(): void
    {
        $sent = fn (array $headers, bool $microsoft = false): bool => ForwardedEmailFiler::sentByThem(['from' => 'momen@jpa.ae', 'headers' => $headers], $microsoft);

        // Exchange's own header: only in a Microsoft 365 mailbox, which strips it from any email from outside.
        $this->assertTrue($sent(['x-ms-exchange-organization-authas' => 'Internal'], microsoft: true));
        $this->assertFalse($sent(['x-ms-exchange-organization-authas' => 'Internal']));

        // A check passed for the address's own domain (or its subdomain) — not for any domain.
        $this->assertTrue($sent(['authentication-results' => 'mx.jpa.ae; dmarc=pass action=none header.from=jpa.ae']));
        $this->assertTrue($sent(['authentication-results' => 'mx.jpa.ae; dkim=pass header.d=mail.jpa.ae; spf=none']));
        $this->assertTrue($sent(['authentication-results' => 'mx.jpa.ae; spf=pass smtp.mailfrom=momen@jpa.ae']));
        $this->assertFalse($sent(['authentication-results' => 'mx.jpa.ae; dkim=pass header.d=gmail.com; spf=pass smtp.mailfrom=someone@gmail.com']));
        $this->assertFalse($sent(['authentication-results' => 'mx.jpa.ae; dkim=pass header.d=jpa.ae; dmarc=fail header.from=jpa.ae']));
        // Only the topmost (the receiving server's): one the sender wrote below it says nothing.
        $this->assertFalse($sent(['authentication-results' => "mx.jpa.ae; spf=fail smtp.mailfrom=jpa.ae\nforged; dmarc=pass header.from=jpa.ae"]));

        // cPanel: sent signed in as them — the topmost such header; X-Sender is anyone's to write.
        $this->assertTrue($sent(['x-get-message-sender-via' => 'server.jpa.ae: authenticated_id: momen@jpa.ae']));
        $this->assertFalse($sent(['x-get-message-sender-via' => "server.jpa.ae: none\nserver.jpa.ae: authenticated_id: momen@jpa.ae"]));
        $this->assertFalse($sent(['x-sender' => 'momen@jpa.ae']));
        $this->assertFalse($sent(['x-get-message-sender-via' => 'authenticated_id: momen@jpa.ae'], microsoft: true));
    }

    public function test_the_filing_mailbox_is_read_by_the_scheduled_run_though_never_sent_from(): void
    {
        app(MatterReplyCollector::class)->collect();

        $this->assertNotNull(Setting::get(MatterReplyCollector::checkedKey('files')));
    }

    public function test_a_forward_whose_reading_died_is_read_again_by_a_later_run(): void
    {
        $forward = $this->forward();
        $stuck = EmailForward::create(['sender_key' => 'files', 'message_id' => MatterEmail::messageId($forward['message_id']), 'outcome' => ForwardedEmailFiler::READING, 'subject' => 'x']);

        // Being read just now: another run leaves it.
        $this->read($forward);
        $this->assertSame(ForwardedEmailFiler::READING, $stuck->fresh()->outcome);

        // Still reading long after: the run that had it died.
        EmailForward::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHour()]);
        $this->read($forward);

        $this->assertSame(ForwardedEmailFiler::FILED, $stuck->fresh()->outcome);
        $this->assertSame(1, EmailForward::count());
    }

    public function test_only_the_filing_mailbox_reads_forwards(): void
    {
        app(MatterReplyCollector::class)->handle([$this->forward()], collect(), 'iflas');

        $this->assertSame(0, EmailForward::count());
    }

    public function test_the_time_quoted_is_read_in_english_gmail_and_arabic(): void
    {
        $this->assertSame('2026-10-22 09:15', ForwardedEmail::time('Thursday, October 22, 2026 9:15 AM')?->format('Y-m-d H:i'));
        $this->assertSame('2026-10-22 21:15', ForwardedEmail::time('Thu, Oct 22, 2026 at 9:15 PM')?->format('Y-m-d H:i'));
        $this->assertSame('2026-10-22 09:15', ForwardedEmail::time('الخميس، ٢٢ أكتوبر، ٢٠٢٦ ٠٩:١٥ ص')?->format('Y-m-d H:i'));
        $this->assertNull(ForwardedEmail::time(''));
    }

    public function test_an_unfiled_emails_attachments_open_from_its_row(): void
    {
        $this->me->givePermissionTo(Permission::findOrCreate('View:UnfiledEmails'));
        Storage::disk('public')->put('email-unfiled/9/email.pdf', '%PDF');
        Storage::disk('public')->put('email-unfiled/9/email/عقد.pdf', '%PDF-contract');
        $email = MatterEmail::create(['direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::PENDING, 'sender_key' => 'iflas', 'message_id' => 'a-1@x.ae', 'subject' => 'مستندات',
            'from' => 'x@x.ae', 'at' => now(), 'files' => [['name' => 'email.pdf', 'path' => 'email-unfiled/9/email.pdf'], ['name' => 'عقد.pdf', 'path' => 'email-unfiled/9/email/عقد.pdf'], ['kind' => MatterEmail::SOURCE, 'name' => 'email.eml', 'path' => 'email-unfiled/9/email.eml']]]);
        $bare = MatterEmail::create(['direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::PENDING, 'sender_key' => 'iflas', 'message_id' => 'a-2@x.ae', 'subject' => 's',
            'from' => 'y@x.ae', 'at' => now(), 'files' => [['name' => 'email.pdf', 'path' => 'email-unfiled/9/email.pdf']]]);

        // The files it came with only: not its PDF, not the original (.eml).
        $this->assertSame(['عقد.pdf'], array_column($email->fileLinks(attachmentsOnly: true), 'name'));

        Livewire::test(UnfiledEmails::class)
            ->assertTableActionVisible('attachments', $email)
            ->assertTableActionHidden('attachments', $bare)
            ->mountTableAction('attachments', $email)
            ->assertMountedActionModalSee('عقد.pdf')
            ->assertMountedActionModalDontSee('email.eml');
    }
}
