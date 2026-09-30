<?php

namespace App\Filament\Mms\Resources\BulkMailCampaigns\Pages;

use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Jobs\ImportSentEmails;
use App\Services\MMS\SenderMailer;
use App\Services\MMS\SentMailImportProgress;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListBulkMailCampaigns extends ListRecords
{
    protected static string $resource = BulkMailCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            $this->importSentEmailsAction(),
        ];
    }

    /**
     * Emails already sent by hand from the sender's mailbox (cPanel over
     * IMAP, or Microsoft 365), brought in as a completed campaign with every
     * recipient marked sent.
     */
    private function importSentEmailsAction(): Actions\Action
    {
        return Actions\Action::make('importSentEmails')
            ->label(__('Import sent emails'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('Create:BulkMailCampaign') ?? false)
            ->modalDescription(__('Reads the sender mailbox\'s Sent folder and creates a completed campaign: one recipient per email whose subject matches, marked as sent on the date it was sent. Nothing is sent. Emails brought in before are skipped.'))
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
            // Runs once the page has answered (it can take a while); the
            // progress window follows it.
            ->action(function (array $data): void {
                $run = SentMailImportProgress::start();

                ImportSentEmails::dispatchAfterResponse($run, $data['name'], $data['sender'], $data['subject'], (string) $data['from'], (string) $data['to'], (int) auth()->id());

                $this->replaceMountedAction('importProgress', ['run' => $run]);
            });
    }

    /**
     * The progress window, opened by the import (not a button of its own).
     */
    public function importProgressAction(): Actions\Action
    {
        return Actions\Action::make('importProgress')
            ->modalHeading(__('Importing sent emails'))
            ->modalContent(fn (array $arguments): View => view('filament.bulk-mail.import-progress', ['run' => $arguments['run'] ?? '']))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->closeModalByClickingAway(false);
    }
}
