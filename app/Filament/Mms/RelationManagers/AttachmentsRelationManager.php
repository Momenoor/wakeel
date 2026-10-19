<?php

namespace App\Filament\Mms\RelationManagers;

use App\Enums\AttachmentCategory;
use App\Filament\Support\AttachFilesAction;
use App\Filament\Support\AttachmentFields;
use App\Filament\Support\SignPdfAction;
use App\Models\Attachment;
use App\Models\Contracts\AttachmentOwner;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/**
 * The files of any record that owns them (an AttachmentOwner: a fee, an allocation, a request, a matter …):
 * who added what and when, opened through the permission check, with "Attach files" and delete. Only the
 * live files are listed — financial evidence that was "deleted" is hidden here though it is kept.
 *
 * Add it to a resource's relations and nothing else:
 *
 *   public static function getRelations(): array
 *   {
 *       return [AttachmentsRelationManager::class];
 *   }
 *
 * Shown to whoever may see the record's matter.
 */
class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'ownedAttachments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Attachments');
    }

    public static function getModelLabel(): string
    {
        return __('Attachment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Attachments');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof AttachmentOwner) {
            return false;
        }

        $matter = AttachmentFields::matterOf($ownerRecord);

        return $matter ? (auth()->user()?->can('view', $matter) ?? false) : true;
    }

    public function isReadOnly(): bool
    {
        // Its own buttons answer to the attachment policy, not to the page being a view page.
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->wrap()
                    ->icon('heroicon-o-paper-clip')
                    // A picture or a PDF opens in the browser, the rest downloads.
                    ->url(fn (Attachment $record): string => $record->url(inline: $record->isPreviewable()))
                    ->openUrlInNewTab(),
                TextColumn::make('category')
                    ->label(__('Category'))
                    ->badge()
                    ->formatStateUsing(fn (?AttachmentCategory $state): ?string => $state?->label())
                    ->color(fn (?AttachmentCategory $state): string => $state?->keepsEvidence() ? 'warning' : 'gray'),
                TextColumn::make('size_bytes')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (?int $state, Attachment $record): ?string => ($bytes = $state ?? (ctype_digit((string) $record->size) ? (int) $record->size : null)) !== null
                        ? Number::fileSize($bytes, maxPrecision: 1)
                        : null)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('Uploaded by'))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                        'whatsapp' => __('WhatsApp'),
                        'email' => __('Email'),
                        'system' => __('System'),
                        null => null,
                        default => __('User'),
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->options(fn (): array => AttachmentCategory::options(...$this->categoriesToFilterBy())),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                AttachFilesAction::make(),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('Preview'))
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->authorize('view')
                    ->visible(fn (Attachment $record): bool => $record->isPreviewable())
                    ->url(fn (Attachment $record): string => $record->url(inline: true))
                    ->openUrlInNewTab(),
                Action::make('download')
                    ->label(__('Download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->iconButton()
                    ->authorize('view')
                    ->url(fn (Attachment $record): string => $record->url()),
                // A PDF: signed and stamped on the Sign PDF page, its signed copy kept here beside it.
                SignPdfAction::make(),
                // The model decides what deleting means: financial evidence is only hidden, anything else
                // goes with its file (Attachment::booted) — either way it is "deleted" for the user.
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash')
                    ->iconButton()
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->successNotificationTitle(__('Attachment deleted.'))
                    ->action(fn (Attachment $record) => $record->delete()),
            ])
            ->emptyStateHeading(__('No attachments yet'))
            ->emptyStateIcon('heroicon-o-paper-clip');
    }

    /**
     * What the record allows, and whatever its files are already (an older file may be in a category the
     * record no longer offers).
     *
     * @return list<AttachmentCategory>
     */
    private function categoriesToFilterBy(): array
    {
        $owner = $this->getOwnerRecord();

        return collect(AttachmentFields::categories($owner))
            ->merge($owner instanceof AttachmentOwner ? $owner->ownedAttachments()->distinct()->pluck('category') : [])
            ->filter(fn ($category): bool => $category instanceof AttachmentCategory)
            ->unique(fn (AttachmentCategory $category): string => $category->value)
            ->values()
            ->all();
    }
}
