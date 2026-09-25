<?php

namespace App\Console\Commands;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Jobs\SendBulkMailBatch;
use App\Models\BulkMailCampaign;
use Illuminate\Console\Command;

class SendBulkCampaignsCommand extends Command
{
    protected $signature = 'mail:send-bulk-campaigns';

    protected $description = 'Send the next batch of every active campaign that is due.';

    public function handle(): void
    {
        $campaigns = BulkMailCampaign::where('status', BulkMailCampaignStatus::Active)
            ->where(function ($query) {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->get();

        if ($campaigns->isEmpty()) {
            $this->info('No active campaign is due.');

            return;
        }

        foreach ($campaigns as $campaign) {
            $sentBefore = $campaign->sent_count;

            // Run here, never queued: this command is the scheduler's own
            // worker. Dispatched onto a database queue (Laravel's default
            // when .env has no QUEUE_CONNECTION) the batch just sat in the
            // jobs table — cPanel has no queue:work to pick it up.
            SendBulkMailBatch::dispatchSync($campaign->id);

            $campaign->refresh();
            $pending = $campaign->recipients()->where('status', BulkMailRecipientStatus::Pending)->count();

            $this->info(sprintf(
                '%s: sent %d now, %d pending, %d left today, status %s.',
                $campaign->name,
                $campaign->sent_count - $sentBefore,
                $pending,
                $campaign->getRemainingDailyLimit(),
                $campaign->status->value,
            ));
        }
    }
}
