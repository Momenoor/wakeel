<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Jobs\SendBulkMailBatch;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\User;
use App\Services\MMS\BulkMailService;
use App\Services\MMS\SentFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Bulk mail with QUEUE_CONNECTION=sync — the job runs inside the request
 * (or the scheduler run) that dispatched it, so each dispatch has to send
 * exactly one batch and leave the rest to the next mail:send-bulk-campaigns.
 */
class BulkMailSyncQueueTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageSent> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            // The campaign's sender switches the app to the smtp mailer —
            // sent into memory here instead of to a real mail server.
            'mail.mailers.smtp.transport' => 'array',
            'mail_senders.senders.test' => [
                'username' => 'sender@example.com',
                'address' => 'sender@example.com',
                'name' => 'Test Sender',
                'password' => 'secret',
                'host' => 'mail.example.com',
                'port' => 587,
                'encryption' => 'tls',
            ],
        ]);

        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event);

        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        $this->app->instance(BulkMailService::class, Mockery::mock(BulkMailService::class, [
            'generate' => 'bulk-mail-pdfs/test.pdf',
        ]));
    }

    private function campaign(int $recipients, int $dailyLimit = 60): BulkMailCampaign
    {
        $campaign = BulkMailCampaign::create([
            'name' => 'Creditors notice',
            'subject' => 'Notice for {{name}}',
            'body' => '<p>Dear {{name}}</p>',
            'from_sender_key' => 'test',
            'daily_send_limit' => $dailyLimit,
            'status' => BulkMailCampaignStatus::Active,
            'created_by' => User::factory()->create()->id,
        ]);

        foreach (range(1, $recipients) as $i) {
            BulkMailRecipient::create([
                'campaign_id' => $campaign->id,
                'email' => ["creditor{$i}@example.com"],
                'name' => "Creditor {$i}",
            ]);
        }

        return $campaign;
    }

    private function statusCount(BulkMailCampaign $campaign, BulkMailRecipientStatus $status): int
    {
        return $campaign->recipients()->where('status', $status)->count();
    }

    public function test_a_dispatch_sends_one_batch_through_the_campaign_sender(): void
    {
        $campaign = $this->campaign(25);

        SendBulkMailBatch::dispatch($campaign->id);

        // One batch of 10 — not all 25 chained into the same request.
        $this->assertCount(10, $this->sent);
        $this->assertSame(10, $this->statusCount($campaign, BulkMailRecipientStatus::Sent));
        $this->assertSame(15, $this->statusCount($campaign, BulkMailRecipientStatus::Pending));
        $this->assertSame(10, $campaign->fresh()->sent_count);

        $message = $this->sent[0]->message;
        $this->assertSame('sender@example.com', $message->getFrom()[0]->getAddress());
        $this->assertSame('creditor1@example.com', $message->getTo()[0]->getAddress());
        $this->assertSame('Notice for Creditor 1', $message->getSubject());

        $recipient = $campaign->recipients()->orderBy('id')->first();
        $this->assertNotNull($recipient->sent_at);
        $this->assertNotNull($recipient->message_id);
        $this->assertSame('bulk-mail-pdfs/test.pdf', $recipient->pdf_path);

        // The app's own mailer is back once the campaign's sender is done.
        $this->assertSame('array', config('mail.default'));
    }

    public function test_the_scheduler_sends_the_rest_and_completes_the_campaign(): void
    {
        $campaign = $this->campaign(25);

        foreach (range(1, 4) as $run) {
            Artisan::call('mail:send-bulk-campaigns');
        }

        $this->assertCount(25, $this->sent);
        $this->assertSame(25, $this->statusCount($campaign, BulkMailRecipientStatus::Sent));
        $this->assertSame(BulkMailCampaignStatus::Completed, $campaign->fresh()->status);

        // Every creditor got exactly one mail.
        $to = array_map(fn (MessageSent $event) => $event->message->getTo()[0]->getAddress(), $this->sent);
        $this->assertCount(25, array_unique($to));
    }

    public function test_the_scheduler_sends_even_when_the_queue_is_the_database(): void
    {
        // What a server gets with no QUEUE_CONNECTION in .env — and no
        // queue worker running on cPanel to process that queue.
        config(['queue.default' => 'database']);

        $campaign = $this->campaign(25);

        Artisan::call('mail:send-bulk-campaigns');

        $this->assertCount(10, $this->sent);
        $this->assertSame(0, \DB::table('jobs')->count());
        $this->assertStringContainsString('sent 10 now, 15 pending', Artisan::output());
    }

    public function test_the_daily_limit_holds(): void
    {
        $campaign = $this->campaign(25, dailyLimit: 12);

        foreach (range(1, 4) as $run) {
            Artisan::call('mail:send-bulk-campaigns');
        }

        $this->assertCount(12, $this->sent);
        $this->assertSame(13, $this->statusCount($campaign, BulkMailRecipientStatus::Pending));
        $this->assertSame(BulkMailCampaignStatus::Active, $campaign->fresh()->status);
    }

    public function test_a_failed_sent_folder_copy_never_sends_the_mail_twice(): void
    {
        $sentFolder = Mockery::mock(SentFolder::class);
        $sentFolder->shouldReceive('save')->andThrow(new \RuntimeException('IMAP login failed'));
        $this->app->instance(SentFolder::class, $sentFolder);

        $campaign = $this->campaign(3);

        SendBulkMailBatch::dispatch($campaign->id);
        SendBulkMailBatch::dispatch($campaign->id);

        $this->assertCount(3, $this->sent);
        $this->assertSame(3, $this->statusCount($campaign, BulkMailRecipientStatus::Sent));
        $this->assertSame(
            'IMAP login failed',
            $campaign->logs()->where('action', 'sent')->first()->metadata['sent_folder_error'],
        );
    }

    public function test_a_paused_campaign_sends_nothing(): void
    {
        $campaign = $this->campaign(3);
        $campaign->update(['status' => BulkMailCampaignStatus::Paused]);

        SendBulkMailBatch::dispatch($campaign->id);

        $this->assertCount(0, $this->sent);
    }
}
