<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Enums\ProgressType;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Concerns\RefreshesMatterPage;
use App\Models\MatterPeriodicReport;
use App\Models\MatterProgress;
use App\Services\MMS\PeriodicReports;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A matter's progress, newest first: what Wakeel recorded as it happened
 * (letters issued and sent, meetings and their minutes, emails to the
 * parties, the reports) and the steps added by hand — documents received
 * from a party, a session … — each with its type, name and date.
 */
class ProgressRelationManager extends RelationManager
{
    use HasRelationManagerPermission;
    use RefreshesMatterPage;

    protected static string $relationship = 'progress';

    /** The periodic report filter's "not in one yet", and the add action's "a new one". */
    private const NOT_IN_REPORT = 'none';

    private const NEW_REPORT = 'new';

    public static function viewPermission(): string
    {
        return ScreenPermissions::MATTER_PROGRESS_TAB;
    }

    public static function getModelLabel(): string
    {
        return __('Progress step');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Progress');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Progress');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')
                ->label(__('Type'))
                ->options(ProgressType::class)
                ->required()
                ->live()
                // The type's name, until one is written.
                ->afterStateUpdated(function ($state, $old, Get $get, Set $set): void {
                    $title = trim((string) $get('title'));
                    $before = ($old instanceof ProgressType ? $old : ProgressType::tryFrom((string) $old))?->getLabel();
                    $type = $state instanceof ProgressType ? $state : ProgressType::tryFrom((string) $state);

                    if ($type && ($title === '' || $title === $before)) {
                        $set('title', $type->getLabel());
                    }
                }),
            DateTimePicker::make('happened_at')
                ->label(__('Date'))
                ->seconds(false)
                ->default(now())
                ->required(),
            TextInput::make('title')
                ->label(__('Name'))
                ->placeholder(__('e.g. Documents received from the plaintiff'))
                ->required()
                ->maxLength(500)
                ->columnSpanFull(),
            Textarea::make('details')
                ->label(__('Details'))
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('happened_at', 'desc')
            ->columns([
                TextColumn::make('happened_at')
                    ->label(__('Date'))
                    // The time only when there is one.
                    ->formatStateUsing(fn ($state) => $state?->format($state->format('H:i') === '00:00' ? 'd/m/Y' : 'd/m/Y H:i'))
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge(),
                TextColumn::make('title')
                    ->label(__('Name'))
                    ->weight('bold')
                    ->description(fn (MatterProgress $record): ?string => $record->details)
                    ->wrap()
                    ->searchable(['title', 'details']),
                TextColumn::make('user.name')
                    ->label(__('By'))
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('automatic')
                    ->label(__('Automatic'))
                    ->state(fn (MatterProgress $record): bool => $record->isAutomatic())
                    ->boolean()
                    ->trueIcon('heroicon-o-bolt')
                    ->falseIcon('heroicon-o-pencil')
                    ->tooltip(fn (MatterProgress $record): string => $record->isAutomatic() ? __('Recorded by Wakeel as it happened') : __('Added by hand'))
                    ->toggleable(),
                TextColumn::make('periodicReport.number')
                    ->label(__('Periodic report'))
                    ->formatStateUsing(fn ($state): string => __('Report no. :number', ['number' => $state]))
                    ->badge()
                    ->color('success')
                    ->placeholder('—'),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('periodicReport:id,number'))
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(ProgressType::class)->multiple(),
                // Those not in a report yet first: the next report's.
                SelectFilter::make('periodic_report_id')
                    ->label(__('Periodic report'))
                    ->options(fn (): array => [
                        self::NOT_IN_REPORT => __('Not in a report yet'),
                        ...$this->getOwnerRecord()->periodicReports()->orderBy('number')->get()->mapWithKeys(fn (MatterPeriodicReport $report): array => [$report->getKey() => $report->label()])->all(),
                    ])
                    ->default(self::NOT_IN_REPORT)
                    ->query(fn (Builder $query, array $data): Builder => match ((string) ($data['value'] ?? '')) {
                        '' => $query,
                        self::NOT_IN_REPORT => $query->whereNull('periodic_report_id'),
                        default => $query->where('periodic_report_id', (int) $data['value']),
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // The steps chosen, and their files, into a periodic report — a new one or one there is.
                    BulkAction::make('addToReport')
                        ->label(__('Add to a periodic report'))
                        ->icon('heroicon-o-document-duplicate')
                        ->visible(fn (): bool => $this->canChange())
                        ->modalDescription(__('Their files — the emails sent and come in, with their originals and attachments, and the signed minutes — are moved into the report\'s folder in the matter\'s OneDrive, and the steps are marked as in it.'))
                        ->schema([
                            Select::make('report')
                                ->label(__('Periodic report'))
                                ->options(fn (): array => [
                                    self::NEW_REPORT => __('A new report (no. :number)', ['number' => (int) $this->getOwnerRecord()->periodicReports()->max('number') + 1]),
                                    ...$this->getOwnerRecord()->periodicReports()->orderBy('number')->get()->mapWithKeys(fn (MatterPeriodicReport $report): array => [$report->getKey() => $report->label()])->all(),
                                ])
                                ->default(self::NEW_REPORT)
                                ->selectablePlaceholder(false)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $report = PeriodicReports::add($this->getOwnerRecord(), $records, $data['report'] === self::NEW_REPORT ? null : (int) $data['report'], auth()->id());

                            Notification::make()->success()
                                ->title(__(':count steps added to :report', ['count' => $records->count(), 'report' => $report->label()]))
                                ->body(__('Their files are being moved to :folder; how it went appears in your notifications.', ['folder' => $report->folder]))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('removeFromReport')
                        ->label(__('Take out of the periodic report'))
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->visible(fn (): bool => $this->canChange())
                        ->requiresConfirmation()
                        ->modalDescription(__('Their files are moved back where they were.'))
                        ->action(function (Collection $records): void {
                            PeriodicReports::remove($records, auth()->id());

                            Notification::make()->success()->title(__('Taken out of the report'))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add progress'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->canChange())
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => auth()->id()]),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->canChange()),
                DeleteAction::make()->visible(fn (): bool => $this->canChange()),
            ])
            ->emptyStateHeading(__('No progress yet'))
            ->emptyStateDescription(__('Letters issued and sent, meetings and their minutes, emails to the parties and the reports appear here by themselves; add any other step by hand.'));
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function canChange(): bool
    {
        return auth()->user()?->can('update', $this->getOwnerRecord()) ?? false;
    }
}
