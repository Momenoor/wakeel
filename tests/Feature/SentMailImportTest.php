<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\ListBulkMailCampaigns;
use App\Jobs\ImportSentEmails;
use App\Livewire\SentMailImportProgressPanel;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\MailSender;
use App\Models\User;
use App\Services\MMS\SentFolder;
use App\Services\MMS\SentMailImporter;
use App\Services\MMS\SentMailImportProgress;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * Emails already sent by hand brought into a completed campaign — from a
 * cPanel mailbox (IMAP, every sent folder) or Microsoft 365 (Graph) — in
 * the background, with a progress window.
 */
class SentMailImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        config(['services.outlook' => [
            'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret',
            'user_email' => 'calendar@firm.ae', 'redirect_uri' => null,
        ]]);
        cache()->forget('outlook_access_token');

        MailSender::create(['key' => 'office', 'name' => 'Office', 'address' => 'office@firm.ae', 'driver' => MailSender::MICROSOFT, 'is_active' => true]);
        MailSender::create(['key' => 'cpanel', 'name' => 'Office', 'address' => 'info@firm.ae', 'driver' => MailSender::SMTP, 'host' => 'mail.firm.ae', 'port' => 465, 'username' => 'info@firm.ae', 'password' => 'secret', 'is_active' => true]);
    }

    private function message(string $id, string $subject, string $to, string $sent): array
    {
        return [
            'internetMessageId' => $id,
            'subject' => $subject,
            'sentDateTime' => $sent,
            'body' => ['contentType' => 'html', 'content' => '<p>Dear client</p>'],
            'toRecipients' => [['emailAddress' => ['address' => $to, 'name' => 'Client '.$id]]],
            'ccRecipients' => [],
        ];
    }

    private function importRun(string $sender = 'office', string $subject = 'invoice'): string
    {
        $run = SentMailImportProgress::start();

        app(SentMailImporter::class)->run($run, 'Invoice reminders', $sender, $subject, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $this->user->id);

        return $run;
    }

    private function fakeGraph(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            if (str_contains($request->url(), 'page=2')) {
                return Http::response(['value' => [$this->message('<b@x>', 'Invoice reminder', 'b@client.ae', '2026-09-02T09:00:00Z')]]);
            }

            return Http::response([
                'value' => [
                    $this->message('<a@x>', 'Invoice reminder', 'a@client.ae', '2026-09-01T09:00:00Z'),
                    $this->message('<c@x>', 'Lunch', 'c@client.ae', '2026-09-01T10:00:00Z'),
                ],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/office%40firm.ae/mailFolders/SentItems/messages?page=2',
            ]);
        });
    }

    /**
     * An IMAP folder holding these [uid, id, subject] emails; only the uids
     * in $fetched may be fetched whole.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $emails
     * @param  list<int>  $fetched
     */
    private function imapFolder(string $name, array $emails, array $fetched): Folder
    {
        $raw = fn (string $id, string $subject): string => "Message-ID: <{$id}@firm.ae>\r\nDate: Tue, 1 Sep 2026 09:00:00 +0400\r\nTo: {$id}@client.ae\r\nSubject: {$subject}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Dear {$id}</p>";

        $query = Mockery::mock(WhereQuery::class);
        $query->shouldReceive('whereSince', 'whereBefore', 'leaveUnread', 'setFetchBody', 'setFetchFlags')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new MessageCollection(array_map(function (array $email) use ($raw): Message {
            $message = Message::fromString($raw($email[1], $email[2]));
            $message->uid = $email[0];

            return $message;
        }, $emails)));

        foreach ($emails as [$uid, $id, $subject]) {
            if (in_array($uid, $fetched, true)) {
                $query->shouldReceive('getMessageByUid')->with($uid)->once()->andReturn(Message::fromString($raw($id, $subject)));
            } else {
                $query->shouldNotReceive('getMessageByUid')->with($uid);
            }
        }

        $folder = Mockery::mock(Folder::class);
        $folder->full_name = $name;
        $folder->shouldReceive('query')->andReturn($query);

        return $folder;
    }

    public function test_the_button_starts_the_import_in_the_background_and_shows_progress(): void
    {
        Bus::fake();

        Livewire::test(ListBulkMailCampaigns::class)
            ->callAction('importSentEmails', ['name' => 'X', 'sender' => 'cpanel', 'subject' => 'invoice', 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertActionMounted('importProgress');

        Bus::assertDispatchedAfterResponse(ImportSentEmails::class, fn (ImportSentEmails $job) => $job->senderKey === 'cpanel' && $job->subject === 'invoice');
    }

    public function test_sent_emails_become_a_completed_campaign_with_sent_recipients(): void
    {
        $this->fakeGraph();
        $run = $this->importRun();

        $campaign = BulkMailCampaign::sole();
        $this->assertSame(BulkMailCampaignStatus::Completed, $campaign->status);
        $this->assertSame(2, $campaign->total_recipients);

        $recipients = BulkMailRecipient::orderBy('sent_at')->get();
        $this->assertSame([['a@client.ae'], ['b@client.ae']], $recipients->pluck('email')->all());
        $this->assertTrue($recipients->every(fn ($r) => $r->status === BulkMailRecipientStatus::Sent));

        $progress = SentMailImportProgress::get($run);
        $this->assertSame(SentMailImportProgress::DONE, $progress['status']);
        $this->assertSame($campaign->id, $progress['campaign_id']);
        $this->assertSame(3, $progress['scanned']);
        $this->assertSame(2, $progress['imported']);
    }

    public function test_running_it_again_brings_nothing_twice(): void
    {
        $this->fakeGraph();
        $this->importRun();
        $second = $this->importRun();

        $this->assertSame(1, BulkMailCampaign::count());
        $this->assertSame(2, BulkMailRecipient::count());
        $this->assertSame(SentMailImportProgress::FAILED, SentMailImportProgress::get($second)['status']);
    }

    public function test_every_sent_folder_is_searched_and_an_email_in_two_counts_once(): void
    {
        $this->mock(SentFolder::class)->shouldReceive('sentFolders')->with('cpanel')->andReturn([
            $this->imapFolder('INBOX.Sent', [[1, 'alpha', 'Invoice Alpha'], [2, 'lunch', 'Lunch']], [1]),
            // Outlook's own folder — where these usually are.
            $this->imapFolder('INBOX.Sent Items', [[7, 'beta', 'Invoice Beta'], [8, 'alpha', 'Invoice Alpha']], [7]),
        ]);

        $run = $this->importRun('cpanel');

        $progress = SentMailImportProgress::get($run);
        $this->assertSame(SentMailImportProgress::DONE, $progress['status'], (string) $progress['error']);
        $this->assertSame(['INBOX.Sent', 'INBOX.Sent Items'], $progress['folders']);
        $this->assertSame(4, $progress['scanned']);
        $this->assertSame(2, $progress['imported']);
        $this->assertSame(['Invoice Alpha', 'Invoice Beta'], BulkMailRecipient::orderBy('sent_subject')->pluck('sent_subject')->all());
    }

    public function test_a_failure_is_shown_in_the_progress_window(): void
    {
        $this->mock(SentFolder::class)->shouldReceive('sentFolders')->andThrow(new RuntimeException('Connection refused'));

        $run = $this->importRun('cpanel');

        Livewire::test(SentMailImportProgressPanel::class, ['run' => $run])
            ->assertSee(__('Import stopped'))
            ->assertSee('Connection refused');

        $this->assertSame(0, BulkMailCampaign::count());
    }

    public function test_the_progress_window_shows_the_counts_and_the_campaign(): void
    {
        $this->fakeGraph();
        $run = $this->importRun();

        Livewire::test(SentMailImportProgressPanel::class, ['run' => $run])
            ->assertSee(__('Import finished'))
            ->assertSee(__('Open the campaign'))
            ->assertSee('Sent Items');
    }

    public function test_without_mail_permission_it_says_what_to_grant(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Access is denied.']], 403),
        ]);

        $progress = SentMailImportProgress::get($this->importRun());

        $this->assertSame(SentMailImportProgress::FAILED, $progress['status']);
        $this->assertStringContainsString('Mail.Read', $progress['error']);
    }

    public function test_each_recipient_keeps_its_own_email_body(): void
    {
        $body = fn (string $company): array => ['content' => "<p>Dear {$company},</p><p>Please find our invoice.</p>"];

        $campaign = app(SentMailImporter::class)->import('Invoices', 'office', [
            [...$this->message('<a@x>', 'Invoice – Alpha LLC', 'a@alpha.ae', '2026-09-01T09:00:00Z'), 'body' => $body('Alpha LLC')],
            [...$this->message('<b@x>', 'Invoice – Beta FZE', 'b@beta.ae', '2026-09-02T09:00:00Z'), 'body' => $body('Beta FZE')],
        ], $this->user->id);

        [$alpha, $beta] = BulkMailRecipient::orderBy('sent_at')->get()->all();

        $this->assertStringContainsString('Dear Alpha LLC', $campaign->renderBody($alpha));
        $this->assertStringContainsString('Dear Beta FZE', $campaign->renderBody($beta));
        $this->assertSame('Invoice – Beta FZE', $campaign->renderSubject($beta));
    }

    public function test_arabic_subjects_match_however_they_were_typed(): void
    {
        // As Outlook stored it: a right-to-left mark in front.
        $subject = "\u{200F}القضية رقم 21/2026 إجراءات إفلاس";

        $this->assertTrue(SentMailImporter::subjectMatches($subject, 'إجراءات إفلاس'));
        $this->assertTrue(SentMailImporter::subjectMatches($subject, 'اجراءات افلاس'));
        $this->assertTrue(SentMailImporter::subjectMatches($subject, 'رقم ٢١/٢٠٢٦'));
        $this->assertTrue(SentMailImporter::subjectMatches($subject, '  إجراءات   إفلاس '));
        $this->assertFalse(SentMailImporter::subjectMatches($subject, 'إجراءات تصفية'));
    }

    public function test_the_recipient_is_named_from_the_letters_salutation(): void
    {
        $this->assertSame('Arco Interiors LLC', SentMailImporter::addressee('<p>السادة/ Arco Interiors LLC                                            ووكيله القانوني المحترمين</p><p>تحية طيبة</p>'));
        $this->assertSame('شركة ألفا للتجارة ذ.م.م', SentMailImporter::addressee('<div><br></div><div>السادة / شركة ألفا للتجارة ذ.م.م&nbsp;&nbsp;&nbsp;&nbsp; ووكيله القانوني المحترمين</div>'));
        $this->assertSame('Beta FZE', SentMailImporter::addressee('<p>&#8207;السادة: Beta FZE المحترمين</p>'));
        $this->assertSame('أحمد علي', SentMailImporter::addressee('<p>إلى السيد/ أحمد علي المحترم</p>'));
        $this->assertNull(SentMailImporter::addressee('<p>تحية طيبة</p><p>Dear Sir</p>'));

        app(SentMailImporter::class)->import('Cases', 'office', [
            [...$this->message('<a@x>', 'القضية رقم 21/2026 إجراءات إفلاس', 'legal@arco.ae', '2026-09-01T09:00:00Z'),
                'body' => ['content' => '<p>السادة/ Arco Interiors LLC      ووكيله القانوني المحترمين</p>']],
            // No salutation: the To address's display name.
            $this->message('<b@x>', 'القضية رقم 22/2026 إجراءات إفلاس', 'b@beta.ae', '2026-09-02T09:00:00Z'),
        ], $this->user->id);

        $this->assertSame(['Arco Interiors LLC', 'Client <b@x>'], BulkMailRecipient::orderBy('sent_at')->pluck('name')->all());
    }

    public function test_the_shared_attachment_is_kept_once_on_the_campaign(): void
    {
        Storage::fake('public');

        $withPdf = fn (string $id): string => "Message-ID: <{$id}@firm.ae>\r\n"
            ."Date: Tue, 1 Sep 2026 09:00:00 +0400\r\n"
            ."To: {$id}@client.ae\r\n"
            ."Subject: Invoice {$id}\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>السادة/ {$id} LLC   المحترمين</p>\r\n"
            ."--b1\r\nContent-Type: application/pdf; name=\"notice.pdf\"\r\nContent-Disposition: attachment; filename=\"notice.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .base64_encode('%PDF-1.4 notice')."\r\n--b1--\r\n";

        $query = Mockery::mock(WhereQuery::class);
        $query->shouldReceive('whereSince', 'whereBefore', 'leaveUnread', 'setFetchBody', 'setFetchFlags')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new MessageCollection(array_map(function (array $email) use ($withPdf): Message {
            $message = Message::fromString($withPdf($email[1]));
            $message->uid = $email[0];

            return $message;
        }, [[1, 'alpha'], [2, 'beta']])));
        $query->shouldReceive('getMessageByUid')->with(1)->andReturn(Message::fromString($withPdf('alpha')));
        $query->shouldReceive('getMessageByUid')->with(2)->andReturn(Message::fromString($withPdf('beta')));

        $folder = Mockery::mock(Folder::class);
        $folder->full_name = 'INBOX.Sent Items';
        $folder->shouldReceive('query')->andReturn($query);
        $this->mock(SentFolder::class)->shouldReceive('sentFolders')->andReturn([$folder]);

        $run = $this->importRun('cpanel');
        $this->assertSame(SentMailImportProgress::DONE, SentMailImportProgress::get($run)['status'], (string) SentMailImportProgress::get($run)['error']);

        $campaign = BulkMailCampaign::sole();
        $this->assertTrue($campaign->has_attachment);
        $this->assertCount(1, $campaign->attachment_path);
        $this->assertStringEndsWith('/notice.pdf', $campaign->attachment_path[0]);
        Storage::disk('public')->assertExists($campaign->attachment_path[0]);
        $this->assertCount(1, Storage::disk('public')->allFiles('mail_attachments/imported'));

        // Every recipient carries it, and is named from the letter.
        foreach (BulkMailRecipient::all() as $recipient) {
            $this->assertSame($campaign->attachment_path, $campaign->attachmentsFor($recipient));
        }
        $this->assertSame(['alpha LLC', 'beta LLC'], BulkMailRecipient::orderBy('name')->pluck('name')->all());
    }

    public function test_an_encoded_arabic_imap_subject_is_read_and_matched(): void
    {
        $subject = 'القضية رقم 21/2026 إجراءات إفلاس';
        $raw = "Message-ID: <case21@firm.ae>\r\n"
            ."Date: Tue, 1 Sep 2026 09:00:00 +0400\r\n"
            ."To: client@alpha.ae\r\n"
            .'Subject: =?UTF-8?B?'.base64_encode($subject)."?=\r\n"
            ."Content-Type: text/html; charset=UTF-8\r\n\r\n"
            .'<p>السادة المحترمين</p>';

        $message = SentMailImporter::imapMessage(Message::fromString($raw));

        $this->assertSame($subject, $message['subject']);
        $this->assertTrue(SentMailImporter::subjectMatches($message['subject'], 'إجراءات إفلاس'));
    }

    public function test_an_imap_message_is_read_into_the_same_shape(): void
    {
        $raw = "Message-ID: <abc@firm.ae>\r\n"
            ."Date: Tue, 1 Sep 2026 09:00:00 +0400\r\n"
            ."From: Office <office@firm.ae>\r\n"
            ."To: Alpha Accounts <accounts@alpha.ae>\r\n"
            ."Cc: boss@alpha.ae\r\n"
            ."Subject: Invoice Alpha\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: text/html; charset=UTF-8\r\n\r\n"
            .'<p>Dear Alpha</p>';

        $message = SentMailImporter::imapMessage(Message::fromString($raw));

        $this->assertStringContainsString('abc@firm.ae', $message['internetMessageId']);
        $this->assertSame('Invoice Alpha', $message['subject']);
        $this->assertSame('accounts@alpha.ae', $message['toRecipients'][0]['emailAddress']['address']);
        $this->assertSame('boss@alpha.ae', $message['ccRecipients'][0]['emailAddress']['address']);
        $this->assertStringContainsString('Dear Alpha', $message['body']['content']);
    }
}
