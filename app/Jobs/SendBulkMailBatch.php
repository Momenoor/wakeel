<?php

namespace App\Jobs;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Events\BulkMailCampaignUpdated;
use App\Mail\BulkMailMessage;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailLog;
use App\Models\BulkMailRecipient;
use App\Models\MatterEmail;
use App\Services\MMS\BulkMailService;
use App\Services\MMS\MatterProgressRecorder;
use App\Services\MMS\SenderMailer;
use App\Services\MMS\SentEmailArchive;
use App\Services\MMS\SentFolder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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
                BulkMailCampaignUpdated::announce($campaign->id);
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
                // Remembered by its recipient, for their reply — with a matter or not.
                MatterEmail::recordSent($campaign->matter_id, $recipient, (string) $campaign->from_sender_key, $sent?->getMessageId(),
                    $campaign->renderSubject($recipient), [...(array) $recipient->email, ...($campaign->cc_emails ?? []), ...($recipient->cc_emails ?? [])], $campaign->created_by);
                $campaign->increment('sent_count');
                // An email to a matter's parties: a step in its progress.
                MatterProgressRecorder::emailSent($campaign);
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
                BulkMailCampaignUpdated::announce($campaign->id);

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
            BulkMailCampaignUpdated::announce($campaign->id);
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

            // An email of a matter: in its OneDrive too (kept with the
            // campaign already, not added to the matter's attachments).
            if ($campaign->matter) {
                app(SentEmailArchive::class)->keep(
                    $campaign->matter,
                    (string) Storage::disk(BulkMailService::DISK)->get($pdfPath),
                    (string) $recipient->name,
                    $campaign->renderSubject($recipient),
                    $campaign->created_by,
                    asAttachment: false,
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Bulk mail {$recipient->id} was sent but its PDF failed: ".$e->getMessage());
            $result['pdf_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Sends through the campaign's mailbox — see SenderMailer. A failure is
     * re-thrown: the caller counts the attempt, marks the recipient failed
     * and stops the batch.
     */
    public function withMailerConfig(BulkMailCampaign $campaign, callable $callable): void
    {
        SenderMailer::using($campaign->sender_config, $callable);
    }
}
