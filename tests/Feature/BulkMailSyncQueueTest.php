<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Events\BulkMailCampaignUpdated;
use App\Jobs\SendBulkMailBatch;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailLog;
use App\Models\MailSender;
use App\Models\BulkMailRecipient;
use App\Models\User;
use App\Services\CronWebhookRunner;
use App\Services\MMS\BulkMailService;
use App\Services\MMS\SentFolder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
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

        \Tests\Support\MailSenders::add('test', [
                'username' => 'sender@example.com',
                'address' => 'sender@example.com',
                'name' => 'Test Sender',
                'password' => 'secret',
                'host' => 'mail.example.com',
                'port' => 587,
                'encryption' => 'tls',
            ]);
        config([
            'queue.default' => 'sync',
            // The campaign's sender switches the app to the smtp mailer —
            // sent into memory here instead of to a real mail server.
            'mail.mailers.smtp.transport' => 'array',
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

    public function test_open_campaign_pages_hear_about_every_recipient_over_pusher(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Event::fake([BulkMailCampaignUpdated::class]);

        $campaign = $this->campaign(3);

        SendBulkMailBatch::dispatch($campaign->id); // sends all 3
        SendBulkMailBatch::dispatch($campaign->id); // finds none left: completes

        // One per recipient sent, one for completing.
        Event::assertDispatchedTimes(BulkMailCampaignUpdated::class, 4);
        $this->assertSame(BulkMailCampaignStatus::Completed, $campaign->fresh()->status);
    }

    public function test_no_announcements_without_a_live_broadcaster(): void
    {
        config(['broadcasting.default' => 'log']);
        Event::fake([BulkMailCampaignUpdated::class]);

        SendBulkMailBatch::dispatch($this->campaign(2)->id);

        Event::assertNotDispatched(BulkMailCampaignUpdated::class);
    }

    public function test_a_campaign_whose_mailbox_is_gone_is_paused_saying_why_and_the_others_still_send(): void
    {
        \Tests\Support\MailSenders::add('other', ['address' => 'other@example.com']);
        $gone = $this->campaign(2);
        $deleted = $this->campaign(2);
        $deleted->update(['from_sender_key' => 'nowhere']);
        $fine = $this->campaign(2);
        $fine->update(['from_sender_key' => 'other']);
        // Deactivated after the campaign was started.
        MailSender::query()->where('key', 'test')->first()->update(['is_active' => false]);

        $this->assertSame(0, Artisan::call('mail:send-bulk-campaigns'));

        // Nothing sent from either, each paused with its reason — and the one after them sent.
        foreach ([$gone, $deleted] as $campaign) {
            $this->assertSame(BulkMailCampaignStatus::Paused, $campaign->fresh()->status);
            $this->assertSame(BulkMailCampaign::MAILBOX_UNAVAILABLE, $campaign->fresh()->paused_reason);
            $this->assertSame(__('bulk_mail.paused_reasons.mailbox_unavailable'), $campaign->fresh()->pausedReasonLabel());
            $this->assertSame(2, $this->statusCount($campaign, BulkMailRecipientStatus::Pending));
        }
        $this->assertCount(2, $this->sent);
        $this->assertSame(2, $this->statusCount($fine, BulkMailRecipientStatus::Sent));

        // Paused: not looked at again the next minute. Started again with its mailbox back: the reason gone, and sent.
        Artisan::call('mail:send-bulk-campaigns');
        $this->assertCount(2, $this->sent);
        MailSender::query()->where('key', 'test')->first()->update(['is_active' => true]);
        $gone->fresh()->update(['status' => BulkMailCampaignStatus::Active]);
        $this->assertNull($gone->fresh()->paused_reason);
        Artisan::call('mail:send-bulk-campaigns');
        $this->assertSame(2, $this->statusCount($gone, BulkMailRecipientStatus::Sent));
    }

    public function test_one_campaign_failing_never_stops_the_campaigns_after_it(): void
    {
        $broken = $this->campaign(1);
        $broken->recipients()->first()->update(['email' => ['broken@example.com']]);
        $fine = $this->campaign(2);
        // Its send fails, and then recording the failure fails too — an error (not an Exception) out of the job.
        Event::listen(MessageSending::class, function (MessageSending $event): void {
            if ($event->message->getTo()[0]->getAddress() === 'broken@example.com') {
                throw new \RuntimeException('Mailbox full');
            }
        });
        BulkMailLog::creating(function (BulkMailLog $log) use ($broken): void {
            if ((int) $log->campaign_id === (int) $broken->id) {
                throw new \TypeError('Cannot record');
            }
        });

        try {
            $this->assertSame(0, Artisan::call('mail:send-bulk-campaigns'));
        } finally {
            BulkMailLog::flushEventListeners();
        }

        $this->assertStringContainsString('failed — Cannot record', Artisan::output());
        $this->assertSame(2, $this->statusCount($fine, BulkMailRecipientStatus::Sent));
    }

    public function test_a_failed_recipient_waits_before_its_next_try_and_does_not_hold_the_others(): void
    {
        config(['mail_senders.retry_delay_minutes' => 30, 'mail_senders.retry_attempts' => 3]);
        $campaign = $this->campaign(3);
        $first = $campaign->recipients()->orderBy('id')->first();
        $tries = 0;
        // An Error, not an Exception: counted as a failed try all the same.
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$tries): void {
            if ($event->message->getTo()[0]->getAddress() === 'creditor1@example.com') {
                $tries++;
                throw new \TypeError('Mailbox unavailable');
            }
        });

        // Its first try fails: it waits half an hour, still pending.
        Artisan::call('mail:send-bulk-campaigns');
        $this->assertSame(1, $tries);
        $this->assertSame(BulkMailRecipientStatus::Pending, $first->fresh()->status);
        $this->assertSame(1, $first->fresh()->attempt_count);
        $this->assertTrue($first->fresh()->next_attempt_at->between(now()->addMinutes(29), now()->addMinutes(31)));

        // The runs a minute apart: the others go, it is not tried — and the campaign is not complete with it waiting.
        Artisan::call('mail:send-bulk-campaigns');
        Artisan::call('mail:send-bulk-campaigns');
        $this->assertSame(1, $tries);
        $this->assertSame(2, $this->statusCount($campaign, BulkMailRecipientStatus::Sent));
        $this->assertSame(BulkMailCampaignStatus::Active, $campaign->fresh()->status);

        // After the wait: tried again, and again after the next — then failed for good.
        $this->travel(31)->minutes();
        Artisan::call('mail:send-bulk-campaigns');
        $this->assertSame(2, $tries);
        $this->assertSame(BulkMailRecipientStatus::Pending, $first->fresh()->status);
        Artisan::call('mail:send-bulk-campaigns');
        $this->assertSame(2, $tries);

        $this->travel(31)->minutes();
        Artisan::call('mail:send-bulk-campaigns');
        $this->assertSame(3, $tries);
        $this->assertSame(BulkMailRecipientStatus::Failed, $first->fresh()->status);
        $this->assertNull($first->fresh()->next_attempt_at);
        $this->assertSame(1, $campaign->fresh()->failed_count);
    }

    public function test_the_campaigns_are_sent_by_one_scheduled_task_every_minute(): void
    {
        $events = collect(app(CronWebhookRunner::class)->schedule()->events())
            ->filter(fn ($event) => CronWebhookRunner::artisanCommand($event) === 'mail:send-bulk-campaigns')
            ->values();

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
    }
}
