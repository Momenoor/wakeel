<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\ProgressType;
use App\Filament\Support\CreditorFields;
use App\Jobs\SendBulkMailBatch;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterCreditor;
use App\Models\MatterEmail;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\EmailReplySender;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\Letters\MinutesSender;
use App\Services\MMS\Letters\MinutesService;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\ReceivedEmailFiling;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mime\Email;
use Tests\Support\FakeOneDrive;
use Tests\Support\MailSenders;
use Tests\TestCase;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * Every way an email goes out from or comes in to the system, each with attachments and without, and exactly what
 * it leaves in the matter's OneDrive folder: which files, under which folders, and nothing else.
 */
class EmailScenariosOneDriveTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 'Work/2026-986';

    private const GENERAL = '04 وارد';

    private const PLAINTIFF = '04 وارد/المدعي';

    private const DEFENDANT = '04 وارد/المدعى عليه';

    private const DEFENDANT_FILES = '06 مستندات المدعى عليه';

    private const SENT = '05 صادر';

    private const CREDITORS = '07 الدائنون';

    private const FIRST_MAILS = '07 الدائنون/000 الإخطارات';

    private const FILES = ['عقد.pdf', 'كشف.xlsx'];

    private FakeOneDrive $oneDrive;

    private Matter $matter;

    private User $user;

    /** @var list<Email> what went out */
    private array $sent = [];

    /** @var list<array<string, mixed>> what the Microsoft 365 mailbox holds */
    private array $mailbox = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-09 10:00:00');
        Storage::fake('public');
        Storage::fake('local');
        $this->withoutDefer();
        MailSenders::add('iflas', ['address' => 'iflas@jpa.ae', 'name' => 'JPA']);
        config([
            'queue.default' => 'sync',
            'mail.mailers.smtp.transport' => 'array',
            'services.outlook' => ['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
        ]);
        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event->message);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->user = User::factory()->create(['email' => 'amr@jpa.ae'])->assignRole('super-admin');
        $this->actingAs($this->user);

        $this->oneDrive = (new FakeOneDrive)->install($this->mailboxAnswer(...));
        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026', 'type_id' => Type::factory()->create(['has_creditors' => true])->id]);
        $this->party('plaintiff', 'شركة المهاد', 'info@mahad.ae', $this->matter);
        $this->party('defendant', 'مؤسسة النخيل', 'legal@nakheel.ae', $this->matter);
        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '2026-986', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => $this->oneDrive->ensure(self::ROOT),
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);

        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, self::GENERAL, 'onedrive');
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, self::SENT, 'onedrive');
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS[ReceivedEmailFiling::PLAINTIFF], self::PLAINTIFF, 'onedrive');
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS[ReceivedEmailFiling::DEFENDANT], self::DEFENDANT, 'onedrive');
        Setting::set(ReceivedEmailFiling::ATTACHMENTS_SETTINGS[ReceivedEmailFiling::DEFENDANT], self::DEFENDANT_FILES, 'onedrive');
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS[ReceivedEmailFiling::CREDITOR], self::CREDITORS.'/{{creditor.number}} {{creditor}}/{{serial.2}}', 'onedrive');
        Setting::set(MatterOneDriveFolders::SENT_TO_CREDITORS, self::CREDITORS.'/{{creditor.number}} {{creditor}}/{{serial.2}}', 'onedrive');
        Setting::set(MatterOneDriveFolders::FIRST_TO_CREDITORS, self::FIRST_MAILS, 'onedrive');
        Setting::clearCache();
    }

    /** @return array<string, array{bool}> */
    public static function attachments(): array
    {
        return ['with attachments' => [true], 'without attachments' => [false]];
    }

    private function party(string $side, string $name, string $address, Matter $matter): Party
    {
        $party = Party::query()->where('name', $name)->first() ?? Party::factory()->create(['name' => $name, 'email' => [$address]]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'party', 'type' => $side]);

        return $party;
    }

    /** @return list<array{name: string, contents: string, mime: string}> */
    private function files(bool $with): array
    {
        return $with ? [
            ['name' => self::FILES[0], 'contents' => '%PDF-contract', 'mime' => 'application/pdf'],
            ['name' => self::FILES[1], 'contents' => 'xlsx-bytes', 'mime' => 'application/vnd.ms-excel'],
        ] : [];
    }

    /** An email as a mailbox gives it. @return array<string, mixed> */
    private function message(bool $with, array $attributes = []): array
    {
        return [
            'message_id' => '<m-'.Str::random(10).'@x.ae>', 'references' => [], 'subject' => 'مستندات القضية 986/2026', 'from' => 'info@mahad.ae', 'from_name' => 'شركة المهاد',
            'to' => ['iflas@jpa.ae'], 'cc' => [], 'at' => now()->toDateTimeString(), 'body' => '<p>مرفق المستندات.</p>',
            'attachments' => $this->files($with), 'raw' => fn (): string => "From: x\r\n\r\nbody",
            ...$attributes,
        ];
    }

    /** The messages read from a mailbox, as a round of the collector reads them. */
    private function read(array $message, string $mailbox = 'iflas'): int
    {
        return app(MatterReplyCollector::class)->handle([$message], MatterEmail::query()->where('direction', MatterEmail::SENT)->get(), $mailbox);
    }

    private function received(string $id): MatterEmail
    {
        return MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->where('message_id', $id)->sole();
    }

    private function sentRecord(array $attributes = []): MatterEmail
    {
        return MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'iflas', 'message_id' => 'sent-1@jpa.ae',
            'subject' => 'طلب بيانات', 'to' => ['clerk@court.ae'], 'at' => now()->subHour(), 'user_id' => $this->user->id, ...$attributes,
        ]);
    }

    /** Every file in the matter's OneDrive folder, by its path inside it. @return list<string> */
    private function saved(): array
    {
        $files = [];

        foreach ($this->oneDrive->items as $id => $item) {
            if (! $item['folder']) {
                $files[] = Str::after($this->oneDrive->pathOf($id), self::ROOT.'/');
            }
        }

        sort($files);

        return $files;
    }

    private static function stem(MatterEmail $email): string
    {
        return pathinfo((string) $email->files[0]['name'], PATHINFO_FILENAME);
    }

    /** What an email come in should have put in OneDrive. @return list<string> */
    private function expected(MatterEmail $email, string $folder, bool $with, ?string $filesFolder = null, bool $eml = true): array
    {
        $stem = self::stem($email);

        return [
            $folder.'/'.$stem.'.pdf',
            ...($eml ? [$folder.'/'.$stem.'.eml'] : []),
            ...array_map(fn (string $file): string => ($filesFolder ?? $folder.'/'.$stem).'/'.$file, $with ? self::FILES : []),
        ];
    }

    /** @param  list<string>  ...$groups */
    private function assertSaved(array ...$groups): void
    {
        $expected = array_merge(...$groups);
        sort($expected);

        $this->assertSame($expected, $this->saved());
    }

    /** The one email kept: its files where they should be, noted so on it, and only its PDF among the matter's attachments. */
    private function assertKept(MatterEmail $email, string $folder, bool $with, ?string $filesFolder = null, bool $eml = true): void
    {
        $stem = self::stem($email);
        $this->assertSaved($this->expected($email, $folder, $with, $filesFolder, $eml));
        $this->assertSame([true, $eml, $with ? 2 : 0, $folder], [$email->onedrive['pdf'], $email->onedrive['eml'], $email->onedrive['attachments'], $email->onedrive['folder']]);
        $this->assertSame([$stem.'.pdf', ...($with ? self::FILES : [])], array_column($email->files, 'name'));
        // The files it came with are in OneDrive only; the PDF is the matter's too.
        $this->assertSame($with ? [null, null] : [], array_column(array_slice($email->files, 1), 'path'));
        $this->assertSame([$stem.'.pdf'], $this->matter->attachments()->pluck('name')->all());

        // No folder made for files it did not come with.
        if (! $with) {
            $this->assertNull($this->oneDrive->find(self::ROOT.'/'.$folder.'/'.$stem));
        }
    }

    /** An email the system sent: its PDF alone in OneDrive — what it went with is counted, not copied there. */
    private function assertSentKept(MatterEmail $email, string $folder, int $wentWith): void
    {
        $this->assertSame([$folder.'/'.$email->onedrive['name']], $this->saved());
        $this->assertSame([true, $folder, $wentWith], [$email->onedrive['pdf'], $email->onedrive['folder'], $email->onedrive['sent_attachments']]);
    }

    /** @return list<array{path: string, name: string}> files chosen on a send screen */
    private function chosen(bool $with): array
    {
        return array_map(function (array $file): array {
            Storage::disk('local')->put('sending/'.$file['name'], $file['contents']);

            return ['path' => Storage::disk('local')->path('sending/'.$file['name']), 'name' => $file['name']];
        }, $this->files($with));
    }

    private function bulkMail(bool $with, array $to, string $name, array $attributes = []): BulkMailCampaign
    {
        foreach ($this->files($with) as $file) {
            Storage::disk('public')->put('bulk/'.$file['name'], $file['contents']);
        }

        $campaign = BulkMailCampaign::create([
            'name' => 'إخطار', 'subject' => 'إخطار الدائنين', 'body' => '<p>السادة {{name}}</p>', 'from_sender_key' => 'iflas', 'matter_id' => $this->matter->id,
            'daily_send_limit' => 60, 'status' => BulkMailCampaignStatus::Active, 'created_by' => $this->user->id,
            'has_attachment' => $with, 'attachment_path' => $with ? array_map(fn (string $file): string => 'bulk/'.$file, self::FILES) : null,
            ...$attributes,
        ]);
        BulkMailRecipient::create(['campaign_id' => $campaign->id, 'email' => $to, 'name' => $name]);
        SendBulkMailBatch::dispatchSync($campaign->id);

        return $campaign;
    }

    /** What the Microsoft 365 mailbox answers; anything else is OneDrive's. */
    private function mailboxAnswer(Request $request): mixed
    {
        $url = rawurldecode($request->url());

        if (! str_contains($url, '/messages') && ! str_contains($url, '/mailFolders')) {
            return null;
        }

        return match (true) {
            str_contains($url, '/mailFolders') => Http::response(['id' => 'folder-'.md5($url)]),
            str_contains($url, '/attachments') => Http::response(['value' => array_map(fn (array $file): array => ['name' => $file['name'], 'contentType' => $file['mime'], 'isInline' => false, 'contentBytes' => base64_encode($file['contents'])], $this->files(true))]),
            str_contains($url, '/$value') => Http::response("From: x\r\n\r\nthe original"),
            default => Http::response(['value' => $this->mailbox]),
        };
    }

    // ── Coming in ───────────────────────────────────────────────────────────

    #[DataProvider('attachments')]
    public function test_an_email_from_the_plaintiff_goes_to_its_sides_folder(bool $with): void
    {
        $this->assertSame(1, $this->read($this->message($with, ['message_id' => '<in@x.ae>'])));

        $email = $this->received('in@x.ae');
        $this->assertSame([$this->matter->id, MatterEmail::FILED, MatterEmail::BY_SUBJECT], [$email->matter_id, $email->status, $email->matched_by]);
        $this->assertKept($email, self::PLAINTIFF, $with);
        $this->assertStringContainsString(__('Incoming'), self::stem($email));
        $this->assertSame(ProgressType::EMAIL_RECEIVED, $this->matter->progress()->sole()->type);
    }

    #[DataProvider('attachments')]
    public function test_an_email_from_the_defendant_has_its_files_in_the_folder_set_for_them(bool $with): void
    {
        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'from' => 'legal@nakheel.ae', 'from_name' => 'مؤسسة النخيل']));

        $email = $this->received('in@x.ae');
        $this->assertKept($email, self::DEFENDANT, $with, self::DEFENDANT_FILES);
        $this->assertSame($with ? self::DEFENDANT_FILES : null, $email->onedrive['attachments_folder'] ?? null);
        // The folder for its files is not made for an email without any.
        $this->assertSame($with, $this->oneDrive->find(self::ROOT.'/'.self::DEFENDANT_FILES) !== null);
    }

    #[DataProvider('attachments')]
    public function test_a_reply_to_an_email_sent_goes_to_the_general_folder_under_the_email_it_answers(bool $with): void
    {
        $sent = $this->sentRecord();

        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'from' => 'clerk@court.ae', 'from_name' => 'المحكمة', 'references' => ['<sent-1@jpa.ae>'], 'subject' => 'RE: طلب بيانات']));

        $email = $this->received('in@x.ae');
        $this->assertSame([$sent->id, MatterEmail::FILED], [$email->parent_id, $email->status]);
        $this->assertKept($email, self::GENERAL, $with);
        $this->assertStringContainsString(__('Reply'), self::stem($email));
        $this->assertSame(ProgressType::REPLY_RECEIVED, $this->matter->progress()->sole()->type);
    }

    #[DataProvider('attachments')]
    public function test_a_partys_reply_to_an_email_sent_goes_to_its_sides_folder(bool $with): void
    {
        $sent = $this->sentRecord(['to' => ['info@mahad.ae']]);

        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'references' => ['<sent-1@jpa.ae>'], 'subject' => 'RE: طلب بيانات']));

        $email = $this->received('in@x.ae');
        $this->assertSame($sent->id, $email->parent_id);
        $this->assertKept($email, self::PLAINTIFF, $with);
    }

    #[DataProvider('attachments')]
    public function test_a_strangers_email_waits_out_of_onedrive_until_filed_by_hand(bool $with): void
    {
        MailSender::query()->where('key', 'iflas')->first()->update(['keep_all_inbox' => true]);

        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'from' => 'nobody@x.com', 'from_name' => 'Nobody', 'subject' => 'استفسار', 'body' => '<p>سؤال</p>']));

        $email = $this->received('in@x.ae');
        $this->assertSame(MatterEmail::PENDING, $email->status);
        $this->assertSame([], $this->saved());
        $this->assertNotEmpty(Storage::disk('public')->allFiles('email-unfiled/'.$email->id));

        app(MatterReplyCollector::class)->file($email, $this->matter, $this->user->id);

        $email->refresh();
        $this->assertSame([MatterEmail::FILED, $this->matter->id], [$email->status, $email->matter_id]);
        $this->assertKept($email, self::GENERAL, $with);
        $this->assertSame([], Storage::disk('public')->allFiles('email-unfiled/'.$email->id));
    }

    #[DataProvider('attachments')]
    public function test_an_email_whose_matter_is_not_certain_is_filed_by_hand_in_its_senders_side(bool $with): void
    {
        $this->party('plaintiff', 'شركة المهاد', 'info@mahad.ae', Matter::factory()->create(['number' => '987', 'year' => '2026']));

        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'subject' => 'مستندات', 'body' => '<p>مرفق.</p>']));

        $email = $this->received('in@x.ae');
        $this->assertSame(MatterEmail::PENDING, $email->status);
        $this->assertCount(2, $email->candidates);
        $this->assertSame([], $this->saved());

        app(MatterReplyCollector::class)->file($email, $this->matter, $this->user->id);

        $this->assertKept($email->refresh(), self::PLAINTIFF, $with);
    }

    private function filingMailbox(): void
    {
        MailSenders::add('files', ['address' => 'files@jpa.ae', 'name' => 'Filing', 'files_forwards' => true, 'driver' => MailSender::MICROSOFT]);
    }

    #[DataProvider('attachments')]
    public function test_an_email_forwarded_to_the_filing_mailbox_is_filed_as_it_came_from_its_sender(bool $with): void
    {
        $this->filingMailbox();
        $quoted = '<hr style="display:inline-block;width:98%"><div id="divRplyFwdMsg" dir="ltr"><font face="Calibri, sans-serif"><b>From:</b> شركة المهاد &lt;info@mahad.ae&gt;<br>'
            .'<b>Sent:</b> Thursday, October 22, 2026 9:15 AM<br><b>To:</b> JPA &lt;iflas@jpa.ae&gt;<br><b>Subject:</b> مستندات الدعوى</font><div>&nbsp;</div></div><div><p>مرفق.</p></div>';

        $this->read($this->message($with, ['from' => 'amr@jpa.ae', 'from_name' => 'Amr', 'to' => ['files@jpa.ae'], 'subject' => 'FW: مستندات الدعوى', 'body' => '<p>986/2026</p>'.$quoted,
            'raw' => fn (): string => '', 'headers' => ['x-ms-exchange-organization-authas' => 'Internal']]), 'files');

        $email = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $this->assertSame([$this->matter->id, MatterEmail::BY_FORWARD, 'مستندات الدعوى'], [$email->matter_id, $email->matched_by, $email->subject]);
        // Forwarded in the text, there is no original to keep.
        $this->assertKept($email, self::PLAINTIFF, $with, eml: false);
    }

    #[DataProvider('attachments')]
    public function test_an_email_forwarded_as_an_attachment_keeps_the_email_itself(bool $with): void
    {
        $this->filingMailbox();
        $files = $with
            ? "--b2\r\nContent-Type: application/pdf; name=\"contract.pdf\"\r\nContent-Disposition: attachment; filename=\"contract.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi1jb250cmFjdA==\r\n"
            : '';
        $original = "From: Office <info@mahad.ae>\r\nTo: iflas@jpa.ae\r\nSubject: Contract\r\nDate: Thu, 22 Oct 2026 09:15:00 +0400\r\nMessage-ID: <orig-1@mahad.ae>\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b2\"\r\n\r\n--b2\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Please find it.</p>\r\n".$files."--b2--\r\n";
        $raw = "From: amr@jpa.ae\r\nTo: files@jpa.ae\r\nSubject: FW: Contract\r\nMessage-ID: <fw@jpa.ae>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n986/2026\r\n--b1\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename=\"original.eml\"\r\n\r\n".$original."--b1--\r\n";

        $this->read($this->message(false, ['from' => 'amr@jpa.ae', 'from_name' => 'Amr', 'to' => ['files@jpa.ae'], 'subject' => 'FW: Contract', 'body' => '<p>986/2026</p>',
            'raw' => fn (): string => $raw, 'headers' => ['x-ms-exchange-organization-authas' => 'Internal']]), 'files');

        $email = $this->received('orig-1@mahad.ae');
        $stem = self::stem($email);
        $this->assertSaved([self::PLAINTIFF.'/'.$stem.'.pdf', self::PLAINTIFF.'/'.$stem.'.eml', ...($with ? [self::PLAINTIFF.'/'.$stem.'/contract.pdf'] : [])]);
        $this->assertSame([true, true, $with ? 1 : 0], [$email->onedrive['pdf'], $email->onedrive['eml'], $email->onedrive['attachments']]);
    }

    #[DataProvider('attachments')]
    public function test_an_email_written_in_outlook_to_someone_outside_is_kept_as_sent(bool $with): void
    {
        $this->read($this->message($with, ['message_id' => '<out@jpa.ae>', 'from' => 'amr@jpa.ae', 'from_name' => 'عمرو', 'to' => ['info@mahad.ae'], 'cc' => ['iflas@jpa.ae']]));

        $email = MatterEmail::query()->where('direction', MatterEmail::SENT)->sole();
        $this->assertSame([$this->matter->id, 'out@jpa.ae', $this->user->id], [$email->matter_id, $email->message_id, $email->user_id]);
        $this->assertKept($email, self::SENT, $with);
        $this->assertStringContainsString(__('Outgoing'), self::stem($email));
    }

    #[DataProvider('attachments')]
    public function test_a_reply_written_in_outlook_goes_beside_the_email_it_answers(bool $with): void
    {
        $this->read($this->message(false, ['message_id' => '<in@x.ae>']));
        $in = $this->received('in@x.ae');

        $this->read($this->message($with, ['message_id' => '<out@jpa.ae>', 'from' => 'amr@jpa.ae', 'from_name' => 'عمرو', 'to' => ['info@mahad.ae'], 'cc' => ['iflas@jpa.ae'], 'references' => ['<in@x.ae>'],
            'subject' => 'RE: مستندات القضية 986/2026', 'at' => now()->addMinute()->toDateTimeString()]));

        $reply = MatterEmail::query()->where('direction', MatterEmail::SENT)->sole();
        $this->assertSame(self::PLAINTIFF, $reply->onedrive['folder']);
        $this->assertSaved($this->expected($in, self::PLAINTIFF, false), $this->expected($reply, self::PLAINTIFF, $with));
    }

    #[DataProvider('attachments')]
    public function test_an_email_put_in_onedrive_by_hand_has_its_pdf_and_files_beside_it(bool $with): void
    {
        $email = app(MatterReplyCollector::class)->keepAdded($this->matter, $this->message($with, ['raw' => null]), 'added@x.ae', '03 مستندات', 'بريد الطرف.eml', $this->user->id);

        $this->assertSame([MatterEmail::RECEIVED, MatterEmail::BY_ONEDRIVE], [$email->direction, $email->matched_by]);
        // The .eml is the one put there: only what is made from it is added.
        $this->assertSaved(['03 مستندات/بريد الطرف.pdf', ...array_map(fn (string $file): string => '03 مستندات/بريد الطرف/'.$file, $with ? self::FILES : [])]);
        $this->assertSame([true, false, $with ? 2 : 0, 'بريد الطرف.eml'], [$email->onedrive['pdf'], $email->onedrive['eml'], $email->onedrive['attachments'], $email->onedrive['added']]);
    }

    #[DataProvider('attachments')]
    public function test_an_email_of_the_office_put_in_onedrive_by_hand_is_kept_as_sent(bool $with): void
    {
        $email = app(MatterReplyCollector::class)->keepAdded($this->matter, $this->message($with, ['from' => 'iflas@jpa.ae', 'from_name' => 'JPA', 'to' => ['info@mahad.ae'], 'raw' => null]), 'added@jpa.ae', '03 مستندات', 'صادر.eml', $this->user->id);

        $this->assertSame([MatterEmail::SENT, 'iflas'], [$email->direction, $email->sender_key]);
        $this->assertSaved(['03 مستندات/صادر.pdf', ...array_map(fn (string $file): string => '03 مستندات/صادر/'.$file, $with ? self::FILES : [])]);
    }

    #[DataProvider('attachments')]
    public function test_a_matter_with_no_onedrive_folder_loses_nothing(bool $with): void
    {
        MatterOneDriveFolder::query()->delete();

        $this->read($this->message($with, ['message_id' => '<in@x.ae>']));

        $email = $this->received('in@x.ae');
        $this->assertSame([], $this->saved());
        $this->assertSame([false, 0], [$email->onedrive['pdf'], $email->onedrive['attachments']]);
        // Kept in the system instead, each with a path to open it by.
        $this->assertSame($with ? 3 : 1, $this->matter->attachments()->count());
        foreach ($email->files as $file) {
            $this->assertNotNull($file['path']);
        }
    }

    #[DataProvider('attachments')]
    public function test_with_no_folder_set_for_received_emails_nothing_is_lost(bool $with): void
    {
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, '', 'onedrive');
        Setting::clearCache();
        $this->sentRecord();

        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'from' => 'clerk@court.ae', 'from_name' => 'المحكمة', 'references' => ['<sent-1@jpa.ae>']]));

        $this->assertFalse($this->received('in@x.ae')->onedrive['pdf']);
        $this->assertSame([], $this->saved());
        $this->assertSame($with ? 3 : 1, $this->matter->attachments()->count());
    }

    #[DataProvider('attachments')]
    public function test_what_is_not_the_matters_saves_nothing_in_onedrive(bool $with): void
    {
        // A copy of what was sent from Wakeel, reaching another office mailbox.
        $this->sentRecord(['message_id' => 'copy@jpa.ae']);
        $this->assertSame(0, $this->read($this->message($with, ['message_id' => '<copy@jpa.ae>', 'from' => 'iflas@jpa.ae', 'from_name' => 'JPA', 'to' => ['info@mahad.ae']])));
        // Between office people only, though it names the matter.
        $this->assertSame(0, $this->read($this->message($with, ['from' => 'amr@jpa.ae', 'from_name' => 'عمرو', 'to' => ['iflas@jpa.ae']])));
        // A stranger's, the mailbox not set to keep every email.
        $this->assertSame(0, $this->read($this->message($with, ['from' => 'nobody@x.com', 'from_name' => 'Nobody', 'subject' => 'عرض', 'body' => '<p>عرض</p>'])));
        // From an address on the ignore list, though it names the matter.
        Setting::set(MatterReplyCollector::IGNORED, ['spam@x.com'], 'mail');
        Setting::clearCache();
        $this->assertSame(0, $this->read($this->message($with, ['from' => 'spam@x.com', 'from_name' => 'Spam'])));

        $this->assertSame([], $this->saved());
        $this->assertSame(0, MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->count());
    }

    #[DataProvider('attachments')]
    public function test_an_email_read_again_or_come_to_a_second_mailbox_is_saved_once(bool $with): void
    {
        MailSenders::add('assistant', ['address' => 'assistant@jpa.ae', 'name' => 'Assistant']);
        $message = $this->message($with, ['message_id' => '<in@x.ae>', 'cc' => ['assistant@jpa.ae']]);
        $this->read($message);
        $saved = $this->saved();

        $this->assertSame(0, $this->read($message));
        $this->assertSame(0, $this->read($message, 'assistant'));
        // The same email, its own Message-ID in the other mailbox.
        $this->assertSame(0, $this->read([...$message, 'message_id' => '<twin@x.ae>'], 'assistant'));

        $this->assertSame($saved, $this->saved());
        $this->assertSame(1, MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->count());
    }

    #[DataProvider('attachments')]
    public function test_read_from_a_microsoft_365_mailbox_as_the_round_reads_it(bool $with): void
    {
        MailSender::query()->where('key', 'iflas')->first()->update(['collects' => false]);
        MailSender::create(['driver' => MailSender::MICROSOFT, 'address' => 'm365@jpa.ae', 'name' => 'Microsoft', 'key' => 'm365', 'is_active' => true]);
        $this->mailbox = [[
            'id' => 'AAMk1', 'subject' => 'مستندات القضية 986/2026', 'internetMessageId' => '<in@x.ae>', 'hasAttachments' => $with, 'parentFolderId' => 'an-inbox',
            'from' => ['emailAddress' => ['address' => 'info@mahad.ae', 'name' => 'شركة المهاد']],
            'toRecipients' => [['emailAddress' => ['address' => 'm365@jpa.ae']]], 'receivedDateTime' => now()->subMinute()->toIso8601String(),
            'body' => ['contentType' => 'html', 'content' => '<p>مرفق.</p>'], 'internetMessageHeaders' => [],
        ]];

        $result = app(MatterReplyCollector::class)->collect(null, 60);

        $this->assertSame([1, []], [$result['emails'], $result['errors']]);
        $this->assertKept($this->received('in@x.ae'), self::PLAINTIFF, $with);
        // Read, not changed: the email stays as it was in the mailbox.
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), '/messages/'));
    }

    #[DataProvider('attachments')]
    public function test_read_from_a_cpanel_mailbox_as_the_round_reads_it(bool $with): void
    {
        $files = $with
            ? "--b\r\nContent-Type: application/pdf; name=\"contract.pdf\"\r\nContent-Disposition: attachment; filename=\"contract.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi1jb250cmFjdA==\r\n"
            : '';
        $message = Message::fromString('From: =?UTF-8?B?'.base64_encode('شركة المهاد')."?= <info@mahad.ae>\r\nTo: iflas@jpa.ae\r\nSubject: =?UTF-8?B?".base64_encode('مستندات القضية 986/2026')."?=\r\n"
            .'Date: '.now()->subMinute()->toRfc2822String()."\r\nMessage-ID: <in@x.ae>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b\"\r\n\r\n"
            ."--b\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>مرفق.</p>\r\n".$files."--b--\r\n");
        $query = Mockery::mock(WhereQuery::class);
        $query->shouldReceive('whereSince', 'leaveUnread', 'setFetchBody', 'setFetchFlags', 'setFetchOrderAsc', 'limit')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new MessageCollection([$message]));
        $query->shouldReceive('errors')->andReturn([]);
        $query->shouldReceive('getMessageByUid')->andReturn($message);
        $inbox = Mockery::mock(Folder::class);
        $inbox->shouldReceive('query')->andReturn($query);
        $mailboxes = Mockery::spy(SentFolder::class);
        $mailboxes->shouldReceive('inbox')->andReturn($inbox);
        $this->app->instance(SentFolder::class, $mailboxes);

        $result = app(MatterReplyCollector::class)->collect(null, 60);

        $this->assertSame([1, []], [$result['emails'], $result['errors']]);
        $email = $this->received('in@x.ae');
        $stem = self::stem($email);
        $this->assertSaved([self::PLAINTIFF.'/'.$stem.'.pdf', self::PLAINTIFF.'/'.$stem.'.eml', ...($with ? [self::PLAINTIFF.'/'.$stem.'/contract.pdf'] : [])]);
        $this->assertSame([true, true, $with ? 1 : 0], [$email->onedrive['pdf'], $email->onedrive['eml'], $email->onedrive['attachments']]);
    }

    // ── Creditors ───────────────────────────────────────────────────────────

    #[DataProvider('attachments')]
    public function test_a_creditors_emails_each_go_in_a_numbered_folder_of_its_own(bool $with): void
    {
        MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $first = self::CREDITORS.'/001 بنك المشرق/01';
        $second = self::CREDITORS.'/001 بنك المشرق/02';
        $from = ['from' => 'claims@mashreq.ae', 'from_name' => 'بنك المشرق'];

        $this->read($this->message($with, ['message_id' => '<one@x.ae>', ...$from]));
        $this->read($this->message($with, ['message_id' => '<two@x.ae>', ...$from, 'subject' => 'مطالبة أخرى 986/2026', 'at' => now()->addMinute()->toDateTimeString()]));

        $one = $this->received('one@x.ae');
        $two = $this->received('two@x.ae');
        $this->assertSame([$first, $second], [$one->onedrive['folder'], $two->onedrive['folder']]);
        $this->assertSaved($this->expected($one, $first, $with), $this->expected($two, $second, $with));
    }

    #[DataProvider('attachments')]
    public function test_a_whole_conversation_with_a_creditor_stays_in_one_numbered_folder(bool $with): void
    {
        MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $thread = self::CREDITORS.'/001 بنك المشرق/01';
        $from = ['from' => 'claims@mashreq.ae', 'from_name' => 'بنك المشرق'];

        // It writes; the office answers from the Emails tab; it answers that; then it writes of something else.
        $this->read($this->message($with, ['message_id' => '<one@x.ae>', ...$from]));
        $one = $this->received('one@x.ae');

        app(EmailReplySender::class)->send($one, ['sender' => 'iflas', 'to' => ['claims@mashreq.ae'], 'subject' => 'RE: مطالبة', 'body' => '<p>تم.</p>'], $this->user->id);
        $mine = MatterEmail::query()->where('direction', MatterEmail::SENT)->sole();
        $this->assertSame($thread, $mine->onedrive['folder']);

        $this->read($this->message($with, ['message_id' => '<two@x.ae>', ...$from, 'references' => ['<'.$mine->message_id.'>'], 'subject' => 'RE: مطالبة', 'at' => now()->addMinute()->toDateTimeString()]));
        $two = $this->received('two@x.ae');
        $this->assertSame([$mine->id, $thread], [$two->parent_id, $two->onedrive['folder']]);

        $this->read($this->message($with, ['message_id' => '<three@x.ae>', ...$from, 'subject' => 'جديد 986/2026', 'at' => now()->addMinutes(2)->toDateTimeString()]));
        $three = $this->received('three@x.ae');
        $this->assertSame(self::CREDITORS.'/001 بنك المشرق/02', $three->onedrive['folder']);

        $this->assertSaved(
            $this->expected($one, $thread, $with),
            [$thread.'/'.$mine->onedrive['name']],
            $this->expected($two, $thread, $with),
            $this->expected($three, self::CREDITORS.'/001 بنك المشرق/02', $with),
        );
    }

    #[DataProvider('attachments')]
    public function test_a_party_that_is_a_creditor_too_is_saved_in_both_capacities(bool $with): void
    {
        MatterCreditor::assign($this->matter, null, 'شركة المهاد', 'info@mahad.ae');
        $folder = self::CREDITORS.'/001 شركة المهاد/01';

        $this->read($this->message($with, ['message_id' => '<in@x.ae>']));

        $email = $this->received('in@x.ae');
        $this->assertSame($folder, $email->onedrive['folder']);
        $this->assertSame([ReceivedEmailFiling::PLAINTIFF, self::PLAINTIFF, $with ? 2 : 0], [$email->onedrive['copies'][0]['side'], $email->onedrive['copies'][0]['folder'], $email->onedrive['copies'][0]['attachments']]);
        $this->assertSaved($this->expected($email, $folder, $with), $this->expected($email, self::PLAINTIFF, $with));
    }

    #[DataProvider('attachments')]
    public function test_someone_made_a_creditor_has_their_email_and_its_files_moved_to_its_folder(bool $with): void
    {
        $this->sentRecord();
        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'from' => 'clerk@court.ae', 'from_name' => 'المحكمة', 'references' => ['<sent-1@jpa.ae>']]));
        $email = $this->received('in@x.ae');
        $this->assertSaved($this->expected($email, self::GENERAL, $with));

        CreditorFields::save($this->matter, ['name' => 'بنك دبي'], ['clerk@court.ae']);

        $email->refresh();
        $this->assertSame(self::CREDITORS.'/001 بنك دبي/01', $email->onedrive['folder']);
        $this->assertSaved($this->expected($email, self::CREDITORS.'/001 بنك دبي/01', $with));
    }

    // ── Going out ───────────────────────────────────────────────────────────

    private function letterTemplate(string $category): LetterTemplate
    {
        $letterhead = Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()]);

        return LetterTemplate::create(['name' => 'نموذج', 'slug' => 'n-'.$category, 'locale' => 'ar', 'category' => $category, 'letterhead_id' => $letterhead->id, 'subject' => 'طلب بيانات', 'body' => '<p>نص.</p>']);
    }

    #[DataProvider('attachments')]
    public function test_a_letter_sent_has_its_pdf_in_the_sent_folder(bool $with): void
    {
        $letter = app(LetterIssuer::class)->issue($this->letterTemplate('letter'), $this->matter, [['name' => 'منى أحمد', 'role' => null, 'emails' => ['mona@example.com']]], []);

        app(LetterMailer::class)->send($letter, 'iflas', attachments: $this->chosen($with));

        // The letter itself, and the files chosen.
        $this->assertCount($with ? 3 : 1, $this->sent[0]->getAttachments());
        $this->assertSentKept(MatterEmail::query()->where('direction', MatterEmail::SENT)->sole(), self::SENT, $with ? 3 : 1);
    }

    #[DataProvider('attachments')]
    public function test_minutes_sent_have_their_email_pdf_in_the_sent_folder(bool $with): void
    {
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => $this->letterTemplate('minutes')->id, 'number' => 1, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);
        app(MinutesService::class)->finalise($minutes, $this->user->id);

        app(MinutesSender::class)->send($minutes->fresh(), [['name' => 'منى أحمد', 'emails' => ['mona@example.com'], 'by_email' => true]], 'iflas', attachments: $this->chosen($with));

        $this->assertCount($with ? 3 : 1, $this->sent[0]->getAttachments());
        $this->assertSentKept(MatterEmail::query()->where('direction', MatterEmail::SENT)->sole(), self::SENT, $with ? 3 : 1);
    }

    #[DataProvider('attachments')]
    public function test_a_bulk_mail_of_a_matter_has_each_pdf_in_the_sent_folder(bool $with): void
    {
        $this->bulkMail($with, ['someone@example.com'], 'جهة ما');

        $this->assertCount($with ? 2 : 0, $this->sent[0]->getAttachments());
        $this->assertSentKept(MatterEmail::query()->where('direction', MatterEmail::SENT)->sole(), self::SENT, $with ? 2 : 0);
    }

    #[DataProvider('attachments')]
    public function test_a_first_mail_to_creditors_goes_in_their_folder_and_its_answer_with_the_general_ones(bool $with): void
    {
        $this->bulkMail($with, ['claims@newbank.ae'], 'بنك الإمارات', ['first_mail' => true]);

        $first = MatterEmail::query()->where('direction', MatterEmail::SENT)->sole();
        $this->assertTrue($first->first_mail);
        $this->assertSame([self::FIRST_MAILS.'/بنك الإمارات.pdf'], $this->saved());

        // Not on the creditors' list yet: its answer is with the general ones — never beside the first mail.
        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'from' => 'claims@newbank.ae', 'from_name' => 'بنك الإمارات', 'references' => ['<'.$first->message_id.'>'], 'subject' => 'RE: إخطار الدائنين']));

        $this->assertSaved([self::FIRST_MAILS.'/بنك الإمارات.pdf'], $this->expected($this->received('in@x.ae'), self::GENERAL, $with));
    }

    #[DataProvider('attachments')]
    public function test_an_email_sent_to_a_creditor_starts_a_numbered_folder_and_its_answer_joins_it(bool $with): void
    {
        MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $thread = self::CREDITORS.'/001 بنك المشرق/01';

        $this->bulkMail($with, ['claims@mashreq.ae'], 'بنك المشرق');
        $sent = MatterEmail::query()->where('direction', MatterEmail::SENT)->sole();
        $this->assertSentKept($sent, $thread, $with ? 2 : 0);

        $this->read($this->message($with, ['message_id' => '<in@x.ae>', 'from' => 'claims@mashreq.ae', 'from_name' => 'بنك المشرق', 'references' => ['<'.$sent->message_id.'>'], 'subject' => 'RE: إخطار الدائنين']));

        $this->assertSaved([$thread.'/'.$sent->onedrive['name']], $this->expected($this->received('in@x.ae'), $thread, $with));
    }

    #[DataProvider('attachments')]
    public function test_a_reply_from_the_emails_tab_goes_out_in_the_thread_and_its_pdf_in_the_sent_folder(bool $with): void
    {
        $this->read($this->message(false, ['message_id' => '<in@x.ae>']));
        $in = $this->received('in@x.ae');
        $paths = [];
        $names = [];
        foreach ($this->files($with) as $i => $file) {
            Storage::disk('local')->put('reply/'.$i, $file['contents']);
            $paths[] = 'reply/'.$i;
            $names['reply/'.$i] = $file['name'];
        }

        $result = app(EmailReplySender::class)->send($in, ['sender' => 'iflas', 'to' => ['info@mahad.ae'], 'subject' => 'RE: مستندات', 'body' => '<p>تم.</p>', 'attachments' => $paths, 'attachment_names' => $names], $this->user->id);

        $this->assertSame(['sent' => true, 'errors' => []], $result);
        $this->assertSame('<in@x.ae>', $this->sent[0]->getHeaders()->get('In-Reply-To')?->getBodyAsString());
        $this->assertCount($with ? 2 : 0, $this->sent[0]->getAttachments());

        $reply = MatterEmail::query()->where('direction', MatterEmail::SENT)->sole();
        $this->assertSame([$in->id, MatterEmail::BY_REPLY, $with ? 2 : 0], [$reply->parent_id, $reply->matched_by, $reply->onedrive['sent_attachments']]);
        $this->assertSaved($this->expected($in, self::PLAINTIFF, false), [self::SENT.'/'.$reply->onedrive['name']]);

        // The party's answer to it is kept under it.
        $this->read($this->message($with, ['message_id' => '<again@x.ae>', 'references' => ['<'.$reply->message_id.'>'], 'subject' => 'RE: مستندات', 'at' => now()->addMinute()->toDateTimeString()]));
        $again = $this->received('again@x.ae');
        $this->assertSame($reply->id, $again->parent_id);
        $this->assertSaved($this->expected($in, self::PLAINTIFF, false), [self::SENT.'/'.$reply->onedrive['name']], $this->expected($again, self::PLAINTIFF, $with));
    }
}
