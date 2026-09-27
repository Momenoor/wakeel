<?php

namespace App\Filament\Mms\Resources\BulkMailCampaigns\Widgets;

use App\Models\BulkMailCampaign;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class CampaignStatsWidget extends BaseWidget
{
    public ?BulkMailCampaign $record = null;

    /**
     * Refreshed by the campaign page instead (its poll, or Pusher) — not on
     * its own every 5 seconds, forever, even for a finished campaign.
     */
    protected ?string $pollingInterval = null;

    #[On('bulk-mail-campaign-refreshed')]
    public function refreshStats(): void
    {
        $this->record?->refresh();
    }

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $successRate = $this->record->total_recipients > 0
            ? round(($this->record->sent_count / $this->record->total_recipients) * 100, 2)
            : 0;

        return [
            Stat::make(__('bulk_mail.fields.total'), $this->record->total_recipients),
            Stat::make(__('bulk_mail.fields.sent'), $this->record->sent_count)
                ->color('success'),
            Stat::make(__('bulk_mail.fields.failed'), $this->record->failed_count)
                ->color('danger'),
            Stat::make(__('Success Rate'), "{$successRate}%"),
            Stat::make(__('Remaining Today'), $this->record->getRemainingDailyLimit()),
        ];
    }
}
