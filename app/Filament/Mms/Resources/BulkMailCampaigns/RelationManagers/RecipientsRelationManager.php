<?php

namespace App\Filament\Mms\Resources\BulkMailCampaigns\RelationManagers;

use App\Enums\BulkMailRecipientStatus;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Mms\Imports\BulkMailRecipientImporter;
use App\Jobs\RebuildCampaignPdfs;
use App\Models\BulkMailRecipient;
use App\Models\MatterEmail;
use App\Services\MMS\BulkMailService;
use App\Support\ScreenPermissions;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecipientsRelationManager extends RelationManager
{
    use HasRelationManagerPermission;

    public static function viewPermission(): string
    {
        return ScreenPermissions::BULK_MAIL_RECIPIENTS;
    }

    protected static string $relationship = 'recipients';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Bulk Mail Recipients');
    }

    /**
     * The campaign page's refresh (poll or Pusher) re-renders the table,
     * so each recipient's status follows the sending live.
     */
    #[On('bulk-mail-campaign-refreshed')]
    public function refreshRecipients(): void
    {
        $this->getOwnerRecord()->refresh();
    }

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

    /** @var Collection<int, Collection<int, MatterEmail>>|null this request's replies, by recipient */
    private ?\Illuminate\Support\Collection $repliesRead = null;

    /**
     * The campaign's replies, by recipient, newest first — read once a
     * request, not a row at a time.
     *
     * @return Collection<int, Collection<int, MatterEmail>>
     */
    private function replies(): \Illuminate\Support\Collection
    {
        return $this->repliesRead ??= MatterEmail::query()
            ->where('direction', MatterEmail::RECEIVED)
            ->whereHas('parent', fn ($q) => $q->where('source_type', (new BulkMailRecipient)->getMorphClass())
                ->whereIn('source_id', $this->getOwnerRecord()->recipients()->select('id')))
            ->with('parent:id,source_id')
            ->latest('at')
            ->get()
            ->groupBy(fn (MatterEmail $reply): int => (int) $reply->parent->source_id);
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
                // Their reply, collected from the inbox of the mailbox it went from.
                TextColumn::make('reply')
                    ->label(__('Reply'))
                    ->state(fn (BulkMailRecipient $record) => $this->replies()->get($record->getKey())?->first()?->at)
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (BulkMailRecipient $record): ?string => ($count = $this->replies()->get($record->getKey())?->count() ?? 0) > 1 ? trans_choice(':count reply|:count replies', $count, ['count' => $count]) : null)
                    ->placeholder('—')
                    ->color('success'),
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
                // Every stored PDF deleted and made again from the mail as it
                // now reads — after a fix to the body, name or attachment.
                Action::make('regeneratePdfs')
                    ->label(__('Regenerate PDFs'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('Deletes every PDF of this campaign and makes them again from the emails as they now read. They are rebuilt in the background; any not ready yet is made when downloaded.'))
                    ->visible(fn ($livewire) => (auth()->user()?->can('update', $livewire->getOwnerRecord()) ?? false)
                        && $livewire->getOwnerRecord()->recipients()->whereNotNull('sent_at')->exists())
                    ->action(function ($livewire): void {
                        $campaign = $livewire->getOwnerRecord();
                        $count = app(BulkMailService::class)->deletePdfs($campaign);

                        RebuildCampaignPdfs::dispatchAfterResponse($campaign->id);

                        Notification::make()
                            ->success()
                            ->title(__(':count PDFs deleted and being made again.', ['count' => $count]))
                            ->send();
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
                Action::make('replies')
                    ->label(__('Reply'))
                    ->icon('heroicon-o-envelope-open')
                    ->color('success')
                    ->visible(fn (BulkMailRecipient $record): bool => $this->replies()->has($record->getKey()))
                    ->modalHeading(fn (BulkMailRecipient $record): string => __('Replies from :name', ['name' => $record->name ?: $record->email]))
                    ->modalContent(fn (BulkMailRecipient $record) => view('filament.mms.bulk-mail.replies', ['replies' => $this->replies()->get($record->getKey())]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
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
                        ->icon('heroicon-o-arrow-path')
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
                        ->icon('heroicon-o-paper-airplane')
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
