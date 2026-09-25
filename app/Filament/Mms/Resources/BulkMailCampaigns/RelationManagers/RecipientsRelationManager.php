<?php

namespace App\Filament\Mms\Resources\BulkMailCampaigns\RelationManagers;

use App\Enums\BulkMailRecipientStatus;
use App\Filament\Mms\Imports\BulkMailRecipientImporter;
use App\Models\BulkMailRecipient;
use App\Services\MMS\BulkMailService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    public static function getModelLabel(): ?string
    {
        return __('Bulk Mail Recipient');
    }

    public static function getPluralModelLabel(): ?string
    {
        return __('Bulk Mail Recipients');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TagsInput::make('email')
                ->label(__('bulk_mail.fields.email'))
                ->required(),
            TextInput::make('name')
                ->label(__('bulk_mail.fields.name')),
            TagsInput::make('cc_emails')
                ->label(__('bulk_mail.fields.cc_emails')),
            KeyValue::make('placeholders')
                ->label(__('bulk_mail.fields.placeholders')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label(__('bulk_mail.fields.email'))
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('bulk_mail.fields.name'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('bulk_mail.fields.status'))
                    ->badge(),
                TextColumn::make('sent_at')
                    ->label(__('bulk_mail.fields.sent_at'))
                    ->dateTime(),
                TextColumn::make('failed_at')
                    ->label(__('bulk_mail.fields.failed_at'))
                    ->dateTime(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function ($livewire) {
                        $livewire->getOwnerRecord()->increment('total_recipients');
                    }),
                ImportAction::make()
                    ->importer(BulkMailRecipientImporter::class)
                    ->options(fn ($livewire) => [
                        'campaign_id' => $livewire->getOwnerRecord()->id,
                    ])
                    ->after(function ($livewire) {
                        $livewire->getOwnerRecord()->loadCount('recipients');
                        $livewire->getOwnerRecord()->update([
                            'total_recipients' => $livewire->getOwnerRecord()->recipients_count,
                        ]);
                    }),
                Action::make('downloadAllPdfs')
                    ->label(__('bulk_mail.actions.download_all_pdfs'))
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->visible(fn ($livewire) => $livewire->getOwnerRecord()->recipients()->whereNotNull('sent_at')->exists())
                    ->action(fn ($livewire) => $this->downloadZip(
                        $livewire->getOwnerRecord()->recipients()->whereNotNull('sent_at')->orderBy('sent_at')->get(),
                    )),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->after(function ($livewire) {
                        $livewire->getOwnerRecord()->decrement('total_recipients');
                    }),
                // Sent or failed — a failed one (e.g. a wrong mailbox
                // password) had no way back from its own row before.
                Action::make('resend')
                    ->label(__('bulk_mail.actions.resend'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn ($record) => in_array($record->status, [BulkMailRecipientStatus::Sent, BulkMailRecipientStatus::Failed]))
                    ->requiresConfirmation()
                    ->action(fn ($record) => $this->resetForResend($record)),
                Action::make('print')
                    ->label(__('bulk_mail.actions.print'))
                    ->icon('heroicon-o-printer')
                    ->url(fn ($record) => route('bulk-mail.preview', [
                        'campaign' => $record->campaign_id,
                        'recipient' => $record->id,
                        'print' => 1,   // auto-triggers print dialog
                    ]))
                    ->visible(fn ($record) => filled($record->sent_at))
                    ->openUrlInNewTab(),

                // Preview only
                Action::make('preview')
                    ->label(__('bulk_mail.actions.preview'))
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => route('bulk-mail.preview', [
                        'campaign' => $record->campaign_id,
                        'recipient' => $record->id,
                    ]))
                    ->visible(fn ($record) => filled($record->sent_at))
                    ->openUrlInNewTab(),
                Action::make('downloadPdf')
                    ->label(__('bulk_mail.actions.download_pdf'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn ($record) => filled($record->sent_at))
                    ->url(fn ($record) => route('bulk-mail.pdf', $record)),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('retry_failed')
                        ->label(__('bulk_mail.actions.retry_failed'))
                        ->action(function (Collection $records) {
                            $records->each(function ($record) {
                                if ($record->status === BulkMailRecipientStatus::Failed) {
                                    $record->update([
                                        'status' => BulkMailRecipientStatus::Pending,
                                        'sent_at' => null,
                                        'failed_at' => null,
                                        'attempt_count' => 0,
                                    ]);
                                    $record->campaign->decrement('failed_count');
                                }
                            });
                        }),
                    BulkAction::make('resend')
                        ->label(__('bulk_mail.actions.resend'))
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records
                            ->filter(fn ($record) => in_array($record->status, [BulkMailRecipientStatus::Sent, BulkMailRecipientStatus::Failed]))
                            ->each(fn ($record) => $this->resetForResend($record))),
                    BulkAction::make('downloadPdf')
                        ->label(__('bulk_mail.actions.download_pdf'))
                        ->icon('heroicon-o-archive-box-arrow-down')
                        ->action(fn (Collection $records) => $this->downloadZip($records->sortBy('sent_at'))),
                ]),
            ]);
    }

    /**
     * Back to Pending, so the next batch sends it again.
     */
    private function resetForResend(BulkMailRecipient $record): void
    {
        if ($record->pdf_path) {
            Storage::disk(BulkMailService::DISK)->delete($record->pdf_path);
        }

        $record->campaign->decrement(
            $record->status === BulkMailRecipientStatus::Sent ? 'sent_count' : 'failed_count'
        );

        $record->update([
            'status' => BulkMailRecipientStatus::Pending,
            'sent_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'attempt_count' => 0,
            'pdf_path' => null,
        ]);
    }

    /**
     * One zip of the recipients' PDFs, each named
     * "date recipient subject.pdf" (max 75 characters).
     *
     * @param  iterable<BulkMailRecipient>  $records
     */
    private function downloadZip(iterable $records): ?BinaryFileResponse
    {
        $zipPath = app(BulkMailService::class)->zip($records);

        if ($zipPath === null) {
            Notification::make()
                ->title(__('bulk_mail.notifications.no_pdfs'))
                ->warning()
                ->send();

            return null;
        }

        $name = Str::slug($this->getOwnerRecord()->name) ?: 'bulk-mail';

        return response()
            ->download($zipPath, $name.'-'.now()->format('Y-m-d').'.zip')
            ->deleteFileAfterSend();
    }
}
