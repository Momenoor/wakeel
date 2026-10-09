<?php

namespace App\Filament\Mms\Pages\Concerns;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\OneDriveFolderReview as Review;
use App\Models\Party;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveFolderReviewer;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * OneDrive Folders → the existing matters' folders: the active matters'
 * folders in the assistants' OneDrive, found by matter number and year, and
 * brought in line with the standard one at a time — renamed to the standard
 * name, the standard subfolders renamed or made, and linked to the matter;
 * or, with none found, made. Each is the office's decision: Apply or Skip.
 * Nothing is ever deleted or moved.
 */
trait ReviewsOneDriveFolders
{
    /**
     * Looks through the assistants' OneDrive — changes nothing.
     */
    public function scanAction(): Action
    {
        return Action::make('scan')
            ->label(__('Scan OneDrive'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->modalDescription(__('Looks through each assistant\'s OneDrive for their active matters\' folders. Nothing is changed — what is found waits here for your decision.'))
            ->schema([
                Select::make('party_id')
                    ->label(__('Assistant'))
                    ->options(fn () => MatterOneDriveFolders::assistantsWithOneDrive()->pluck('name', 'id'))
                    ->placeholder(__('All assistants')),
            ])
            ->action(function (array $data): void {
                $assistant = filled($data['party_id'] ?? null) ? Party::find($data['party_id']) : null;
                $summary = app(OneDriveFolderReviewer::class)->scan($assistant);

                Notification::make()
                    ->title(__('Scanned: :rows matter folders of :assistants assistants', ['rows' => $summary['rows'], 'assistants' => $summary['assistants']]))
                    ->body($summary['errors'] ? implode("\n", $summary['errors']) : null)
                    ->{$summary['errors'] ? 'warning' : 'success'}()
                    ->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Review::query()->with(['matter.court', 'matter.type', 'party']))
            ->defaultSort('status')
            ->columns([
                TextColumn::make('matter')
                    ->label(__('Matter'))
                    ->state(fn (Review $r): string => $r->matter ? $r->matter->number.'/'.$r->matter->year : '—')
                    ->description(fn (Review $r): ?string => collect([$r->matter?->court?->name, $r->matter?->type?->name])->filter()->implode(' — ') ?: null)
                    ->url(fn (Review $r): ?string => $r->matter ? MatterResource::getUrl('view', ['record' => $r->matter]) : null)
                    ->weight('bold'),
                TextColumn::make('party.name')->label(__('Assistant')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->formatStateUsing(fn (string $state): string => Review::statuses()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Review::FOUND, Review::MULTIPLE => 'warning',
                        Review::MISSING => 'info',
                        Review::DONE, Review::STANDARD => 'success',
                        Review::FAILED => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Review $r): ?string => $r->status === Review::FAILED ? $r->error : null),
                TextColumn::make('candidates')
                    ->label(__('In OneDrive'))
                    ->state(fn (Review $r): HtmlString => new HtmlString(collect($r->candidates ?? [])
                        ->map(fn (array $f): string => '<div dir="auto"><a href="'.e($f['webUrl']).'" target="_blank" style="text-decoration: underline;">'.e($f['name']).'</a></div>')
                        ->implode('') ?: '—'))
                    ->html(),
                TextColumn::make('standard_name')
                    ->label(__('Standard name'))
                    ->extraAttributes(['dir' => 'auto'])
                    ->wrap(),
                TextColumn::make('decided_at')->label(__('Decided'))->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(Review::statuses())
                    ->multiple()
                    ->default(Review::OPEN),
                SelectFilter::make('party_id')
                    ->label(__('Assistant'))
                    ->relationship('party', 'name'),
            ])
            ->recordActions([
                $this->applyAction(),
                Action::make('skip')
                    ->label(__('Skip'))
                    ->icon(Heroicon::OutlinedForward)
                    ->color('gray')
                    ->visible(fn (Review $r): bool => in_array($r->status, Review::OPEN, true))
                    ->action(fn (Review $r) => $r->update(['status' => Review::SKIPPED, 'decided_by' => auth()->id(), 'decided_at' => now()])),
                Action::make('reconsider')
                    ->label(__('Reconsider'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (Review $r): bool => $r->status === Review::SKIPPED)
                    ->action(fn (Review $r) => $r->update([
                        'status' => match (count($r->candidates ?? [])) {
                            0 => Review::MISSING,
                            1 => Review::FOUND,
                            default => Review::MULTIPLE,
                        },
                        'decided_by' => null,
                        'decided_at' => null,
                    ])),
                Action::make('open')
                    ->label(__('Open'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (Review $r): bool => filled($r->web_url))
                    ->url(fn (Review $r): ?string => $r->web_url, shouldOpenInNewTab: true),
            ])
            ->emptyStateHeading(__('Nothing to review'))
            ->emptyStateDescription(__('Scan OneDrive to find the active matters\' folders.'));
    }

    /**
     * The decision: which folder (or a new one), what will be done to it —
     * shown before anything is changed.
     */
    private function applyAction(): Action
    {
        return Action::make('apply')
            ->label(__('Apply'))
            ->icon(Heroicon::OutlinedCheck)
            ->color('primary')
            ->visible(fn (Review $r): bool => in_array($r->status, Review::OPEN, true))
            ->modalHeading(fn (Review $r): string => __('Standardise the folder of :matter', ['matter' => $r->matter?->number.'/'.$r->matter?->year]))
            ->modalSubmitActionLabel(__('Apply'))
            ->fillForm(fn (Review $r): array => ['folder' => $r->candidates[0]['id'] ?? 'new'])
            ->schema(fn (Review $r): array => [
                Radio::make('folder')
                    ->label(__('Folder'))
                    ->options([
                        ...collect($r->candidates ?? [])->mapWithKeys(fn (array $f): array => [$f['id'] => $f['name']])->all(),
                        'new' => __('Make a new folder ":name"', ['name' => $r->standard_name]),
                    ])
                    ->required()
                    ->live(),
                Placeholder::make('plan')
                    ->label(__('What will be done'))
                    ->content(function (Get $get) use ($r): HtmlString {
                        try {
                            $steps = app(OneDriveFolderReviewer::class)->plan($r, $get('folder') === 'new' ? null : $get('folder'));
                        } catch (Throwable $e) {
                            return new HtmlString('<span style="color: rgb(220,38,38);">'.e($e->getMessage()).'</span>');
                        }

                        return new HtmlString('<ul style="list-style: disc; padding-inline-start: 1.25rem;">'.collect($steps)->map(fn (array $s): string => '<li dir="auto">'.match ($s['action']) {
                            'rename' => e(__('Rename')).': <code>'.e($s['from']).'</code> → <strong>'.e($s['to']).'</strong>',
                            'create' => e(__('Make')).': <strong>'.e($s['to']).'</strong>',
                            default => e(__('Keep')).': '.e($s['to']),
                        }.'</li>')->implode('').'</ul><p style="opacity: .7; font-size: .85em;">'.e(__('Files are not touched; nothing is deleted or moved.')).'</p>');
                    }),
            ])
            ->action(function (Review $r, array $data): void {
                $review = app(OneDriveFolderReviewer::class)->apply($r, ($data['folder'] ?? 'new') === 'new' ? null : $data['folder'], auth()->id());

                $review->status === Review::DONE
                    ? Notification::make()->success()->title(__('Folder standardised and linked to the matter'))->send()
                    : Notification::make()->danger()->title(__('Not done'))->body($review->error)->send();
            });
    }
}
