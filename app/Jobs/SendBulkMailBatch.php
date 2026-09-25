<?php

namespace App\Jobs;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Mail\BulkMailMessage;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailLog;
use App\Models\BulkMailRecipient;
use App\Services\MMS\BulkMailService;
use App\Services\MMS\SentFolder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBulkMailBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $campaignId,
        public int $batchSize = 10
    ) {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $campaign = BulkMailCampaign::find($this->campaignId);

        if (! $campaign || $campaign->status !== BulkMailCampaignStatus::Active) {
            return;
        }

        $remainingLimit = $campaign->getRemainingDailyLimit();

        if ($remainingLimit <= 0) {
            Log::info("Campaign {$this->campaignId} reached daily limit.");

            return;
        }

        $recipients = BulkMailRecipient::where('campaign_id', $this->campaignId)
            ->where('status', BulkMailRecipientStatus::Pending)
            ->limit(min($this->batchSize, $remainingLimit))
            ->get();

        if ($recipients->isEmpty()) {
            if (BulkMailRecipient::where('campaign_id', $this->campaignId)
                ->where('status', BulkMailRecipientStatus::Pending)
                ->count() === 0) {
                $campaign->update(['status' => BulkMailCampaignStatus::Completed]);
            }

            return;
        }

        foreach ($recipients as $recipient) {
            $email = is_array($recipient->email) ? implode('; ', $recipient->email) : $recipient->email;
            try {
                // 1. Send mail — stop everything if this fails
                $sent = null;
                $this->withMailerConfig($campaign, function () use ($campaign, $recipient, &$sent) {
                    $sent = Mail::to($recipient->email)
                        ->cc(array_merge($campaign->cc_emails ?? [], $recipient->cc_emails ?? []))
                        ->bcc($campaign->bcc_emails ?? [])
                        ->send(new BulkMailMessage($campaign, $recipient));
                });

                // 2. Recorded as Sent the moment the mail server accepted it.
                // Doing this only after the Sent-folder copy and the PDF meant
                // any failure there (or the request timing out, which is easy
                // with QUEUE_CONNECTION=sync) left the recipient Pending, and
                // the next batch mailed them again — up to retry_attempts times.
                $recipient->update([
                    'status' => BulkMailRecipientStatus::Sent,
                    'sent_at' => now(),
                    'message_id' => $sent?->getMessageId(),
                ]);
                $campaign->increment('sent_count');
            } catch (\Exception $e) {
                // Log the failure
                Log::error("Bulk mail batch stopped. Failed for recipient {$email}: ".$e->getMessage());

                $recipient->increment('attempt_count');

                if ($recipient->attempt_count >= config('mail_senders.retry_attempts', 3)) {
                    $recipient->update([
                        'status' => BulkMailRecipientStatus::Failed,
                        'failed_at' => now(),
                        'failure_reason' => $e->getMessage(),
                    ]);
                    $campaign->increment('failed_count');
                }

                BulkMailLog::create([
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'action' => 'failed',
                    'metadata' => ['error' => $e->getMessage()],
                    'timestamp' => now(),
                ]);

                // Stop the entire batch — do NOT dispatch next batch
                return;
            }

            // 3. Copy to the mailbox's Sent folder and keep a PDF of it. The
            // mail has already gone out, so a failure here is logged but
            // never sends it again.
            $archived = $this->archive($campaign, $recipient, $sent?->toString());

            BulkMailLog::create([
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipient->id,
                'action' => 'sent',
                'metadata' => $archived,
                'timestamp' => now(),
            ]);
        }

        // Only reached if ALL recipients in this batch succeeded.
        //
        // On the sync queue there is no next batch to chain: the delay is
        // ignored, so the dispatch would run straight away inside this one
        // — the whole daily limit in a single web request, until PHP's time
        // limit kills it mid-send. The scheduler's mail:send-bulk-campaigns
        // (every minute) sends the next batch instead.
        if ($this->onSyncQueue()) {
            return;
        }

        if ($campaign->getRemainingDailyLimit() > 0) {
            static::dispatch($this->campaignId, $this->batchSize)->delay(now()->addSeconds(30));
        }
    }

    private function onSyncQueue(): bool
    {
        $connection = $this->job?->getConnectionName() ?? $this->connection ?? config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    /**
     * @return array<string, string>
     */
    private function archive(BulkMailCampaign $campaign, BulkMailRecipient $recipient, ?string $rawMessage): array
    {
        $result = [];

        try {
            if ($rawMessage !== null) {
                app(SentFolder::class)->save($campaign, $rawMessage);
            }
        } catch (\Throwable $e) {
            Log::warning("Bulk mail {$recipient->id} was sent but not copied to the Sent folder: ".$e->getMessage());
            $result['sent_folder_error'] = $e->getMessage();
        }

        try {
            $pdfPath = app(BulkMailService::class)->generate($campaign, $recipient);
            $recipient->update(['pdf_path' => $pdfPath]);
            $result['pdf_path'] = $pdfPath;
        } catch (\Throwable $e) {
            Log::warning("Bulk mail {$recipient->id} was sent but its PDF failed: ".$e->getMessage());
            $result['pdf_error'] = $e->getMessage();
        }

        return $result;
    }

    public function withMailerConfig(BulkMailCampaign $campaign, callable $callable): void
    {
        $original = [
            'mail.default' => config('mail.default'),
            'mail.mailers.smtp.host' => config('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => config('mail.mailers.smtp.port'),
            'mail.mailers.smtp.username' => config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => config('mail.mailers.smtp.password'),
            'mail.mailers.smtp.encryption' => config('mail.mailers.smtp.encryption'),
            'mail.from.address' => config('mail.from.address'),
            'mail.from.name' => config('mail.from.name'),
        ];
        try {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $campaign->sender_config['host'],
                'mail.mailers.smtp.port' => $campaign->sender_config['port'],
                'mail.mailers.smtp.username' => $campaign->sender_config['username'],
                'mail.mailers.smtp.password' => $campaign->sender_config['password'],
                'mail.mailers.smtp.encryption' => $campaign->sender_config['encryption'],
                'mail.from.address' => $campaign->sender_config['address'],
                'mail.from.name' => $campaign->sender_config['name'],
            ]);
            app('mail.manager')->purge('smtp');
            $callable();
        } catch (\Throwable $e) {
            // Must re-throw: the caller's own handler counts the attempt, marks the
            // recipient Failed and stops the batch. Swallowing it here let a failed
            // send fall through and be recorded as Sent, so an SMTP/IMAP outage
            // produced a campaign reporting 100% delivered with nothing retried.
            Log::error('Failed to execute mailer configuration callback: '.$e->getMessage());
            throw $e;
        } finally {
            config($original);
            app('mail.manager')->purge('smtp');
        }

    }
}
