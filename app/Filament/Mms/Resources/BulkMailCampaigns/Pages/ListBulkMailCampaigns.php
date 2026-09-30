<?php

namespace App\Filament\Mms\Resources\BulkMailCampaigns\Pages;

use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Services\MMS\OutlookSentMailImporter;
use App\Services\MMS\SenderMailer;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Throwable;

class ListBulkMailCampaigns extends ListRecords
{
    protected static string $resource = BulkMailCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            $this->importFromOutlookAction(),
        ];
    }

    /**
     * Emails already sent from Outlook, outside the system, brought in as a
     * completed campaign with every recipient marked sent.
     */
    private function importFromOutlookAction(): Actions\Action
    {
        return Actions\Action::make('importFromOutlook')
            ->label(__('Import sent emails from Outlook'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('Create:BulkMailCampaign') ?? false)
            ->modalDescription(__('Reads the sender\'s Outlook Sent Items and creates a completed campaign: one recipient per email whose subject matches, marked as sent on the date it was sent. Nothing is sent. Emails brought in before are skipped.'))
            ->schema([
                TextInput::make('name')
                    ->label(__('Campaign name'))
                    ->required()
                    ->maxLength(255),
                Select::make('sender')
                    ->label(__('Sent from'))
                    ->options(fn (): array => SenderMailer::options())
                    ->required(),
                TextInput::make('subject')
                    ->label(__('Subject contains'))
                    ->required(),
                DatePicker::make('from')
                    ->label(__('Sent from date'))
                    ->required(),
                DatePicker::make('to')
                    ->label(__('Sent to date'))
                    ->default(now())
                    ->afterOrEqual('from')
                    ->required(),
            ])
            ->action(function (array $data, Actions\Action $action): void {
                $importer = app(OutlookSentMailImporter::class);

                try {
                    $mailbox = (string) (SenderMailer::sender($data['sender'])['address'] ?? '');
                    $messages = $importer->sentMessages($mailbox, $data['subject'], Carbon::parse($data['from']), Carbon::parse($data['to']));
                    $campaign = $importer->import($data['name'], $data['sender'], $messages, (int) auth()->id());
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title(__('Could not continue'))->body($exception->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__(':count sent emails brought in.', ['count' => $campaign->total_recipients]))
                    ->send();

                $this->redirect(BulkMailCampaignResource::getUrl('view', ['record' => $campaign]));
            });
    }
}
