<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;

/**
 * How many files a row has, in a list: a paper-clip and the number (nothing when there are none), the count
 * read in the list's own query. Only the live files count (what was removed is not).
 *
 *   ->columns([..., AttachmentCount::column()])                       // a record that owns files
 *   AttachmentCount::column('ownedAttachments')->label(__('Receipts'))
 */
final class AttachmentCount
{
    public static function column(string $relationship = 'ownedAttachments'): TextColumn
    {
        return TextColumn::make(Str::snake($relationship).'_count')
            ->label(__('Attachments'))
            ->counts($relationship)
            ->sortable()
            ->icon(fn (mixed $state): ?string => (int) $state > 0 ? 'heroicon-o-paper-clip' : null)
            ->formatStateUsing(fn (mixed $state): string => (int) $state > 0 ? (string) (int) $state : '')
            ->tooltip(fn (mixed $state): ?string => (int) $state > 0 ? __(':count attached files', ['count' => (int) $state]) : null)
            ->alignCenter();
    }
}
