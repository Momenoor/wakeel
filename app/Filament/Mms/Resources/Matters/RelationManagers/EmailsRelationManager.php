<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Filament\Concerns\HasRelationManagerPermission;
use App\Models\MatterEmail;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A matter's email log: what was sent from it (letters, minutes, bulk mail)
 * and what came in from its parties — put on it by Wakeel or chosen by hand.
 * Each can be opened here: who, when, how its matter was known, and — for an
 * email that came in — its PDF and the files it came with.
 */
class EmailsRelationManager extends RelationManager
{
    use HasRelationManagerPermission;

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
            // Sent, and received ones that are on the matter — not those waiting for a matter, or set aside.
            ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('direction', MatterEmail::SENT)
                ->orWhere('status', MatterEmail::FILED)))
            ->defaultSort('at', 'desc')
            ->columns([
                TextColumn::make('at')
                    ->label(__('Date'))
                    ->dateTime('d/m/Y H:i')
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
                    ->description(fn (MatterEmail $record): ?string => ($n = count($record->attachmentFiles()))
                        ? trans_choice(':count file|:count files', $n, ['count' => $n]) : null)
                    ->searchable()
                    ->wrap(),
                // What was kept of an email that came in — and where.
                TextColumn::make('pdf_saved')
                    ->label(__('PDF'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => self::saved($record, self::pdfUrl($record) !== null))
                    ->color(fn (?string $state): string => self::savedColor($state)),
                TextColumn::make('eml_saved')
                    ->label(__('EML'))
                    ->tooltip(__('The original email, saved in the matter\'s OneDrive'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => self::saved($record, (bool) $record->eml_saved))
                    ->color(fn (?string $state): string => self::savedColor($state)),
                TextColumn::make('attachments')
                    ->label(__('Attachments'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => $record->direction === MatterEmail::SENT
                        ? null
                        : ($record->attachmentFiles() !== [] ? trans_choice(':count attachment|:count attachments', count($record->attachmentFiles()), ['count' => count($record->attachmentFiles())]) : __('None')))
                    ->color(fn (?string $state): string => $state === __('None') ? 'gray' : 'info'),
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
                Action::make('view')
                    ->label(__('Details'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (MatterEmail $record): string => (string) ($record->subject ?: __('(no subject)')))
                    ->modalContent(fn (MatterEmail $record) => view('filament.mms.matter-email', [
                        'email' => $record,
                        'files' => self::fileLinks($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
            ])
            ->emptyStateHeading(__('No emails yet'))
            ->emptyStateDescription(__('Emails sent from this matter, and those that come in from its parties, are listed here.'));
    }

    /** "Saved" or "Not saved" for an email that came in; nothing for one that went out. */
    private static function saved(MatterEmail $email, bool $saved): ?string
    {
        return $email->direction === MatterEmail::SENT ? null : ($saved ? __('Saved') : __('Not saved'));
    }

    private static function savedColor(?string $state): string
    {
        return $state === __('Saved') ? 'success' : 'gray';
    }

    /** The URL of the email's PDF (the first file kept), when there is one. */
    private static function pdfUrl(MatterEmail $email): ?string
    {
        $path = $email->files[0]['path'] ?? null;

        return filled($path) && Storage::disk('public')->exists($path) ? Storage::disk('public')->url($path) : null;
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
            ->filter(fn ($file): bool => filled($file['path'] ?? null) && Storage::disk('public')->exists($file['path']))
            ->map(fn (array $file): array => ['name' => (string) $file['name'], 'url' => Storage::disk('public')->url($file['path']), 'kind' => $file['kind'] ?? null])
            ->values()
            ->all();
    }
}
