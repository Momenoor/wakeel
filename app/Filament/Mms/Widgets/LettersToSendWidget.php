<?php

namespace App\Filament\Mms\Widgets;

use App\Enums\LetterSendChannel;
use App\Filament\Support\LetterSendFields;
use App\Models\LetterSendRequest;
use App\Services\MMS\Letters\LetterSendRequests;
use App\Support\Dates;
use App\Support\RequestMemo;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;

/**
 * The letters assistants issued for their administrator to send (LetterSendRequests), still to send: each with
 * its matter, recipients, who asked and the ways it should go — those done apart from those still to do — and
 * To send, where the SMS is copied and each way is ticked once done. An administrator sees those of the
 * assistants they administer, an assistant their own, a super admin all; shown only while there are some.
 */
class LettersToSendWidget extends TableWidget
{
    // With the page, not as a request of its own after it (as the other dashboard tables).
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::hasAny();
    }

    /** Nothing left on the dashboard once the last one is sent from here. */
    public function render(): View
    {
        RequestMemo::forget('letters-to-send.');

        return self::hasAny() ? parent::render() : view('filament.widgets.hidden');
    }

    private static function hasAny(): bool
    {
        $user = auth()->user();

        return $user !== null && RequestMemo::remember('letters-to-send.any.'.$user->getKey(), fn (): bool => LetterSendRequests::openFor($user)->exists());
    }

    public function table(Table $table): Table
    {
        $labels = fn (array $channels): array => array_map(fn (string $channel): string => LetterSendChannel::from($channel)->label(), $channels);

        return $table
            ->heading(__('Letters to send'))
            ->description(__('Issued by an assistant for their administrator to send. Open To send, send the letter by each way asked for, and tick it.'))
            ->query(fn () => LetterSendRequests::openFor(auth()->user())->with(['letter.recipients', 'letter.matter', 'requester'])->latest('id'))
            ->extraAttributes(['class' => 'fi-dashboard-compact-table'])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('matter')
                    ->label(__('Matter'))
                    ->state(fn (LetterSendRequest $record): string => LetterSendRequests::matterLabel($record->letter))
                    ->url(fn (LetterSendRequest $record): string => LetterSendRequests::url($record))
                    ->weight('bold'),
                TextColumn::make('letter.reference')
                    ->label(__('Letter'))
                    ->description(fn (LetterSendRequest $record): ?string => $record->letter?->subject)
                    ->wrap(),
                TextColumn::make('letter.recipients.name')
                    ->label(__('To'))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList(),
                TextColumn::make('requester.name')
                    ->label(__('Asked by'))
                    ->description(fn (LetterSendRequest $record): ?string => $record->created_at?->format(Dates::dateTimeFormat())),
                TextColumn::make('pending')
                    ->label(__('To be sent by'))
                    ->state(fn (LetterSendRequest $record): array => $labels($record->pendingChannels()))
                    ->badge()
                    ->color('warning'),
                TextColumn::make('sent')
                    ->label(__('Sent by'))
                    ->state(fn (LetterSendRequest $record): array => $labels($record->doneChannels()))
                    ->badge()
                    ->color('success'),
            ])
            ->recordActions([
                LetterSendFields::action(),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->url(fn (LetterSendRequest $record): string => route('letters.pdf', $record->matter_letter_id), shouldOpenInNewTab: true),
            ]);
    }
}
