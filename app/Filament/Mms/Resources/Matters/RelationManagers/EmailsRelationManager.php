<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Concerns\RefreshesMatterPage;
use App\Filament\Mms\Pages\UnfiledEmails;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Support\RegenerateEmailAction;
use App\Jobs\EmailJob;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterReplyCollector;
use App\Support\Dates;
use App\Support\Documents;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A matter's email log: what was sent from it (letters, minutes, bulk mail)
 * and what came in from its parties — put on it by Wakeel or chosen by hand.
 * Each can be opened here: who, when, how its matter was known, and — for an
 * email that came in — its PDF and the files it came with.
 */
class EmailsRelationManager extends RelationManager
{
    use HasRelationManagerPermission;
    use RefreshesMatterPage;

    protected static string $relationship = 'emails';

    public static function viewPermission(): string
    {
        return ScreenPermissions::MATTER_EMAILS_TAB;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Emails');
    }

    public static function getModelLabel(): string
    {
        return __('Email');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Emails');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            // Sent, and received ones that are on the matter — filed, or a reply (kept with no status) —
            // not those waiting for a matter, or set aside.
            ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('direction', MatterEmail::SENT)
                ->orWhere('status', MatterEmail::FILED)
                ->orWhereNull('status')))
            ->defaultSort('at', 'desc')
            ->columns([
                TextColumn::make('at')
                    ->label(__('Date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('direction')
                    ->label(__('Direction'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === MatterEmail::SENT ? __('Sent') : __('Received'))
                    ->color(fn (string $state): string => $state === MatterEmail::SENT ? 'info' : 'success'),
                TextColumn::make('party')
                    ->label(__('From / To'))
                    ->state(fn (MatterEmail $record): string => $record->direction === MatterEmail::SENT
                        ? implode('، ', (array) $record->to)
                        : (string) $record->from)
                    ->wrap()
                    ->limit(80),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->weight('bold')
                    ->description(fn (MatterEmail $record): ?string => EmailJob::note($record, $this->getTableRecords()))

                    ->searchable()
                    ->wrap(),
                // What of an email that came in is in the matter's OneDrive.
                TextColumn::make('pdf_saved')
                    ->label(__('PDF in OneDrive'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => $record->oneDriveLabel('pdf'))
                    ->color(fn (?string $state): string => self::color($state)),
                TextColumn::make('eml_saved')
                    ->label(__('EML in OneDrive'))
                    ->tooltip(__('The original email, saved in the matter\'s OneDrive'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => $record->oneDriveLabel('eml'))
                    ->color(fn (?string $state): string => self::color($state)),
                TextColumn::make('attachments_saved')
                    ->label(__('Attachments in OneDrive'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => $record->oneDriveLabel('attachments'))
                    ->color(fn (?string $state): string => self::color($state)),
                TextColumn::make('matched_by')
                    ->label(__('Matter known by'))
                    ->formatStateUsing(fn (?string $state): string => MatterEmail::matchedByLabel($state))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label(__('Direction'))
                    ->options([MatterEmail::SENT => __('Sent'), MatterEmail::RECEIVED => __('Received')]),
            ])
            ->recordActions([
                // The email's PDF, as kept.
                Action::make('open')
                    ->label(__('Open the email'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (MatterEmail $record): bool => self::pdfUrl($record) !== null)
                    ->url(fn (MatterEmail $record): ?string => self::pdfUrl($record), shouldOpenInNewTab: true),
                // Filed on the wrong matter: to another, with its files and its step in the progress.
                Action::make('move')
                    ->label(__('Move to another matter'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                    ->modalWidth('lg')
                    ->modalDescription(__('Its PDF, original and attachments go to the other matter (in the system and its OneDrive), and its step in the progress with them.'))
                    ->schema([
                        Select::make('matter_id')
                            ->label(__('Matter'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => array_diff_key(UnfiledEmails::searchMatters($search), [$this->getOwnerRecord()->getKey() => true]))
                            ->getOptionLabelUsing(fn ($value): ?string => UnfiledEmails::matterLabels([(int) $value])[(int) $value] ?? null)
                            ->helperText(__('Search by its number: 986, 986/2026 or 2026/986.'))
                            ->required(),
                    ])
                    ->action(function (MatterEmail $record, array $data): void {
                        $matter = Matter::findOrFail($data['matter_id']);
                        abort_unless(auth()->user()?->can('update', $matter), 403);

                        try {
                            $oneDrive = app(MatterReplyCollector::class)->move($record, $matter, auth()->id());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('The email could not be moved'))->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Moved to matter :matter.', ['matter' => $matter->year.'/'.$matter->number]))
                            ->body($oneDrive['moved'] ? null : __('Nothing of it was found in this matter\'s OneDrive to move.'))
                            ->actions([Action::make('open')->label(__('View'))->url(MatterResource::getUrl('view', ['record' => $matter]))])
                            ->send();
                    }),
                Action::make('view')
                    ->label(__('Details'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (MatterEmail $record): string => (string) ($record->subject ?: __('(no subject)')))
                    ->schema(fn (MatterEmail $record): array => self::details($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                // The less used, last, in the row's "…" menu.
                ActionGroup::make([
                    // Read from OneDrive itself what is there.
                    Action::make('checkOneDrive')
                        ->label(__('Check OneDrive'))
                        ->icon('heroicon-o-cloud-arrow-down')
                        ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED)
                        ->action(fn (MatterEmail $record) => $this->checkOneDrive([$record])),
                    // Its PDF made wrongly (garbled letters): made again from the mailbox, its files in their places.
                    RegenerateEmailAction::make(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false),
                ]),
            ])
            ->headerActions([
                Action::make('checkAllOneDrive')
                    ->label(__('Check OneDrive'))
                    ->tooltip(__('Reads from OneDrive which PDFs, originals and attachments of the emails that came in are there'))
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('gray')
                    ->action(fn () => $this->checkOneDrive($this->getOwnerRecord()->emails()->where('direction', MatterEmail::RECEIVED)->where(fn ($q) => $q->where('status', MatterEmail::FILED)->orWhereNull('status'))->get()->all())),
            ])
            ->emptyStateHeading(__('No emails yet'))
            ->emptyStateDescription(__('Emails sent from this matter, and those that come in from its parties, are listed here.'));
    }

    /**
     * Reads from the matter's OneDrive what is there of these emails.
     *
     * @param  list<MatterEmail>  $emails
     */
    private function checkOneDrive(array $emails): void
    {
        try {
            $read = app(MatterOneDriveFolders::class)->checkEmails($this->getOwnerRecord(), $emails);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title(__('OneDrive could not be read'))->body($e->getMessage())->send();

            return;
        }

        $read
            ? Notification::make()->success()->title(__('OneDrive checked'))->send()
            : Notification::make()->warning()->title(__('Nothing to check'))->body(__('This matter has no OneDrive folder, or no folder is set for received emails.'))->send();
    }

    private static function color(?string $state): string
    {
        return match ($state) {
            __('Saved') => 'success',
            __('Not saved') => 'danger',
            __('Partly saved') => 'warning',
            // An email that went out, with its files: "2 attachments".
            default => is_string($state) && preg_match('/\d/', $state) ? 'info' : 'gray',
        };
    }

    /** The URL of the email's PDF (the first file kept), when there is one. */
    private static function pdfUrl(MatterEmail $email): ?string
    {
        $path = $email->files[0]['path'] ?? null;

        return Documents::linkable($path) ? MatterEmail::fileUrl($path) : null;
    }

    /**
     * An email's details window: who, when, how its matter was known, what
     * of it is in OneDrive — then the files kept of it, each to open.
     *
     * @return list<Component>
     */
    private static function details(MatterEmail $email): array
    {
        $sent = $email->direction === MatterEmail::SENT;
        $folder = ((array) $email->onedrive)['folder'] ?? null;
        $files = self::fileLinks($email);

        $rows = array_filter([
            'at' => [__('Date'), $email->at?->format(Dates::dateTimeFormat())],
            'from' => [__('From'), $sent ? $email->user?->name : $email->from],
            'to' => [__('To'), implode('، ', (array) $email->to)],
            'cc' => [__('Cc'), implode('، ', (array) $email->cc)],
            'matched_by' => [__('Matter known by'), $sent ? null : MatterEmail::matchedByLabel($email->matched_by)],
            // What was kept of an email that came in.
            // Inside the matter's OneDrive folder — as it was when the email was saved.
            'onedrive_folder' => [__('OneDrive folder'), filled($folder) ? $folder : null],
            'onedrive_pdf' => [__('PDF in OneDrive'), $email->oneDriveLabel('pdf')],
            'onedrive_eml' => [__('EML in OneDrive'), $email->oneDriveLabel('eml')],
            'onedrive_attachments' => [__('Attachments in OneDrive'), $email->oneDriveLabel('attachments')],
        ], fn (array $row): bool => filled($row[1]));

        return [
            ...array_map(
                // Each value in its own direction: Arabic names beside Latin addresses read in order.
                fn (string $name, array $row): TextEntry => TextEntry::make($name)->label($row[0])->state($row[1])->inlineLabel()->extraAttributes(['dir' => 'auto']),
                array_keys($rows),
                $rows,
            ),
            TextEntry::make('kept_files')
                ->label(__('Files'))
                ->visible($files !== [])
                ->state(array_map(
                    // The first is the email itself, as a PDF.
                    fn (array $file, int $index): array => [...$file, 'name' => $index === 0 ? $file['name'].' ('.__('the email as PDF').')' : $file['name']],
                    $files,
                    array_keys($files),
                ))
                ->formatStateUsing(fn (array $state): string => $state['name'])
                ->url(fn (array $state): string => $state['url'], shouldOpenInNewTab: true)
                ->color('primary')
                ->listWithLineBreaks()
                ->bulleted()
                ->extraAttributes(['dir' => 'auto']),
            Text::make(__('No file was kept for this email.'))
                ->color('gray')
                ->visible($files === [] && ! $sent),
        ];
    }

    /**
     * The files kept for an email, each with a link: the PDF first, then the
     * files it came with.
     *
     * @return list<array{name: string, url: string, kind: ?string}>
     */
    private static function fileLinks(MatterEmail $email): array
    {
        return collect((array) $email->files)
            ->filter(fn ($file): bool => Documents::linkable($file['path'] ?? null))
            ->map(fn (array $file): array => ['name' => (string) $file['name'], 'url' => MatterEmail::fileUrl($file['path']), 'kind' => $file['kind'] ?? null])
            ->values()
            ->all();
    }
}
