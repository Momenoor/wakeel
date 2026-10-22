<?php

namespace App\Filament\Support;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Support\Integrations;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;

/**
 * The files a record owns, inside an infolist: each one's name (opened in a new tab — in the browser for a
 * PDF or a picture), its category and a delete button for whoever may (AttachmentPolicy). Shown only when
 * there is a file.
 *
 *   AttachmentList::make()                       // in a record's infolist, or a RepeatableEntry's schema
 *   AttachmentList::make()->label(__('Receipts'))
 *
 * Load `ownedAttachments` with the records shown (each one fetches its own otherwise).
 */
final class AttachmentList
{
    public static function make(string $name = 'ownedAttachments'): RepeatableEntry
    {
        return RepeatableEntry::make($name)
            ->label(__('Attachments'))
            ->columnSpanFull()
            ->contained(false)
            ->columns(6)
            ->visible(fn ($record): bool => (bool) $record?->{$name}?->isNotEmpty())
            ->schema([
                TextEntry::make('name')
                    ->hiddenLabel()
                    ->icon('heroicon-o-paper-clip')
                    ->color('primary')
                    ->url(fn (Attachment $record): string => $record->url(inline: $record->isPreviewable()))
                    ->openUrlInNewTab()
                    ->columnSpan(fn (): int => Integrations::azureOn() ? 3 : 4),
                // Where the file is, once Azure is set.
                TextEntry::make('disk')
                    ->hiddenLabel()
                    ->badge()
                    ->state(fn (Attachment $record): string => $record->keptIn())
                    ->color(fn (Attachment $record): string => $record->isInAzure() ? 'info' : 'gray')
                    ->icon(fn (Attachment $record): string => $record->isInAzure() ? 'heroicon-o-cloud' : 'heroicon-o-server')
                    ->visible(fn (): bool => Integrations::azureOn()),
                TextEntry::make('category')
                    ->hiddenLabel()
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?AttachmentCategory $state): string => $state?->label() ?? ''),
                Actions::make([SignPdfAction::make('signOwnedAttachment'), self::deleteAction()])->alignEnd(),
            ]);
    }

    private static function deleteAction(): Action
    {
        return Action::make('deleteOwnedAttachment')
            ->label(__('Delete'))
            ->iconButton()
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Attachment $record): bool => auth()->user()?->can('delete', $record) ?? false)
            ->requiresConfirmation()
            ->successNotificationTitle(__('Attachment deleted.'))
            ->action(function (Attachment $record, Action $action, $component): void {
                // An invoice, a receipt …: only hidden, its file kept (Attachment).
                $record->delete();
                $action->success();

                $livewire = $component->getLivewire();
                if (method_exists($livewire, 'getRecord')) {
                    $livewire->getRecord()?->refresh();
                }
                $livewire->dispatch('$refresh');
            });
    }
}
