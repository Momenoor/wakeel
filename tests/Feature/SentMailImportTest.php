<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\ListBulkMailCampaigns;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\MailSender;
use App\Models\User;
use App\Services\MMS\SentFolder;
use App\Services\MMS\SentMailImporter;
use RuntimeException;
use Webklex\PHPIMAP\Message;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Emails already sent by hand brought into a completed campaign — from a
 * Microsoft 365 mailbox (Graph) or a cPanel one (IMAP).
 */
class SentMailImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        config(['services.outlook' => [
            'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret',
            'user_email' => 'calendar@firm.ae', 'redirect_uri' => null,
        ]]);
        cache()->forget('outlook_access_token');

        MailSender::create(['key' => 'office', 'name' => 'Office', 'address' => 'office@firm.ae', 'driver' => MailSender::MICROSOFT, 'is_active' => true]);
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

    private function import(): void
    {
        Livewire::test(ListBulkMailCampaigns::class)
            ->callAction('importSentEmails', [
                'name' => 'Invoice reminders (Outlook)',
                'sender' => 'office',
                'subject' => 'invoice',
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ]);
    }

    public function test_sent_emails_become_a_completed_campaign_with_sent_recipients(): void
    {
        $this->fakeGraph();
        $this->import();

        $campaign = BulkMailCampaign::sole();
        $this->assertSame(BulkMailCampaignStatus::Completed, $campaign->status);
        $this->assertSame('Invoice reminder', $campaign->subject);
        $this->assertSame(2, $campaign->total_recipients);
        $this->assertSame(2, $campaign->sent_count);

        $recipients = BulkMailRecipient::orderBy('sent_at')->get();
        $this->assertSame([['a@client.ae'], ['b@client.ae']], $recipients->pluck('email')->all());
        $this->assertTrue($recipients->every(fn ($r) => $r->status === BulkMailRecipientStatus::Sent));
        $this->assertSame('2026-09-01', $recipients->first()->sent_at->toDateString());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'users/office%40firm.ae/mailFolders/SentItems/messages'));
    }

    public function test_running_it_again_brings_nothing_twice(): void
    {
        $this->fakeGraph();
        $this->import();
        $this->import();

        $this->assertSame(1, BulkMailCampaign::count());
        $this->assertSame(2, BulkMailRecipient::count());
    }

    public function test_without_mail_permission_it_says_what_to_grant(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Access is denied.']], 403),
        ]);

        Livewire::test(ListBulkMailCampaigns::class)
            ->callAction('importSentEmails', ['name' => 'X', 'sender' => 'office', 'subject' => 'x', 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertNotified();

        $this->assertSame(0, BulkMailCampaign::count());
    }

    public function test_each_recipient_keeps_its_own_email_body(): void
    {
        $body = fn (string $company): array => ['content' => "<p>Dear {$company},</p><p>Please find our invoice.</p>"];

        $campaign = app(SentMailImporter::class)->import('Invoices', 'office', [
            [...$this->message('<a@x>', 'Invoice – Alpha LLC', 'a@alpha.ae', '2026-09-01T09:00:00Z'), 'body' => $body('Alpha LLC')],
            [...$this->message('<b@x>', 'Invoice – Beta FZE', 'b@beta.ae', '2026-09-02T09:00:00Z'), 'body' => $body('Beta FZE')],
        ], auth()->id());

        [$alpha, $beta] = BulkMailRecipient::orderBy('sent_at')->get()->all();

        $this->assertStringContainsString('Dear Alpha LLC', $campaign->renderBody($alpha));
        $this->assertStringContainsString('Dear Beta FZE', $campaign->renderBody($beta));
        $this->assertSame('Invoice – Beta FZE', $campaign->renderSubject($beta));
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
        $this->assertSame('2026-09-01', substr($message['sentDateTime'], 0, 10));
    }

    public function test_a_cpanel_sender_is_read_over_imap(): void
    {
        MailSender::create(['key' => 'cpanel', 'name' => 'Office', 'address' => 'info@firm.ae', 'driver' => MailSender::SMTP, 'host' => 'mail.firm.ae', 'port' => 465, 'username' => 'info@firm.ae', 'password' => 'secret', 'is_active' => true]);

        $this->mock(SentFolder::class)
            ->shouldReceive('folder')->once()->with('cpanel')
            ->andThrow(new RuntimeException('Connection refused'));

        Http::fake();

        Livewire::test(ListBulkMailCampaigns::class)
            ->callAction('importSentEmails', ['name' => 'X', 'sender' => 'cpanel', 'subject' => 'x', 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertNotified();

        Http::assertNothingSent();
        $this->assertSame(0, BulkMailCampaign::count());
    }
}
