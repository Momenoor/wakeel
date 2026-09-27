<?php

namespace App\Filament\Mms\Resources\BulkMailCampaigns\Pages;

use App\Enums\BulkMailCampaignStatus;
use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Filament\Mms\Resources\BulkMailCampaigns\Widgets\CampaignStatsWidget;
use App\Jobs\SendBulkMailBatch;
use App\Mail\BulkMailMessage;
use App\Models\BulkMailRecipient;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Mail;

class ViewBulkMailCampaign extends ViewRecord
{
    protected static string $resource = BulkMailCampaignResource::class;

    /**
     * The page's own view adds the refresh poll (see pollInterval()).
     */
    protected string $view = 'filament.mms.bulk-mail.view-campaign';

    /**
     * Live progress over Pusher: every recipient sent or failed, and the
     * campaign completing, refreshes this page (BulkMailCampaignUpdated).
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            ...parent::getListeners(),
            'echo-private:bulk-mail-campaign.'.$this->record->getKey().',.campaign.updated' => 'refreshCampaign',
        ];
    }

    /**
     * Re-reads the campaign (status, and the Start/Pause buttons that
     * depend on it) and has the stats and the recipients table refresh.
     */
    public function refreshCampaign(): void
    {
        $this->record->refresh();
        // The view shows the campaign through its (disabled) form, filled
        // once on load — refill it so the status field follows too.
        $this->fillForm();

        $this->dispatch('bulk-mail-campaign-refreshed');
    }

    /**
     * While a campaign is sending: with Pusher the page is already live, so
     * only a slow safety net; without it, every 10 seconds. Nothing to poll
     * for once it isn't active.
     */
    public function pollInterval(): ?string
    {
        if ($this->record->status !== BulkMailCampaignStatus::Active) {
            return null;
        }

        return filled(config('filament.broadcasting.echo')) ? '60s' : '10s';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('send_test')
                ->label(__('bulk_mail.actions.send_test'))
                ->icon('heroicon-o-paper-airplane')
                ->action(function ($record) {
                    $recipient = new BulkMailRecipient([
                        'email' => auth()->user()->email,
                        'name' => auth()->user()->name,
                    ]);
                    // Through the campaign's own sender account, exactly as
                    // the campaign will send — the app's default mailer
                    // proved nothing about that account's SMTP settings.
                    (new SendBulkMailBatch($record->id))->withMailerConfig(
                        $record,
                        fn () => Mail::to(auth()->user()->email)->send(new BulkMailMessage($record, $recipient)),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('bulk_mail.notifications.test_sent'))
                        ->send();
                }),

            Actions\Action::make('start_campaign')
                ->label(__('bulk_mail.actions.start'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [BulkMailCampaignStatus::Draft, BulkMailCampaignStatus::Paused]))
                ->action(function () {
                    $this->record->update(['status' => BulkMailCampaignStatus::Active]);

                    // A scheduled campaign waits for mail:send-bulk-campaigns
                    // to pick it up once scheduled_at has passed.
                    if ($this->record->scheduled_at === null || $this->record->scheduled_at->isPast()) {
                        // Sent right here, not queued — nothing on cPanel
                        // runs a queue worker (see mail:send-bulk-campaigns).
                        SendBulkMailBatch::dispatchSync($this->record->id);
                    }

                    Notification::make()
                        ->success()
                        ->title(__('bulk_mail.status.active'))
                        ->send();
                }),

            Actions\Action::make('pause_campaign')
                ->label(__('bulk_mail.actions.pause'))
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn () => $this->record->status === BulkMailCampaignStatus::Active)
                ->action(function () {
                    $this->record->update(['status' => BulkMailCampaignStatus::Paused]);

                    Notification::make()
                        ->warning()
                        ->title(__('bulk_mail.status.paused'))
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CampaignStatsWidget::class,
        ];
    }
}
