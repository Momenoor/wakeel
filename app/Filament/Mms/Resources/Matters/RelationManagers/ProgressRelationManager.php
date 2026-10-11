<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Enums\ProgressType;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Concerns\RefreshesMatterPage;
use App\Models\MatterEmail;
use App\Models\MatterProgress;
use App\Services\MMS\MatterReplyCollector;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
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
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(ProgressType::class)->multiple(),
            ])
            ->headerActions([
                // Now, not waiting for the next round (every ten minutes).
                Action::make('collectReplies')
                    ->label(__('Check for replies'))
                    ->icon('heroicon-o-envelope-open')
                    ->color('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->emails()->where('direction', MatterEmail::SENT)->exists())
                    ->action(function (): void {
                        @set_time_limit(300);
                        $result = app(MatterReplyCollector::class)->collect($this->getOwnerRecord()->getKey());

                        Notification::make()
                            ->title(trans_choice('No new replies.|:count new reply kept.|:count new replies kept.', $result['replies'], ['count' => $result['replies']]))
                            ->body($result['errors'] ? implode("\n", $result['errors']) : null)
                            ->status($result['errors'] ? 'warning' : 'success')
                            ->persistent($result['errors'] !== [])
                            ->send();
                    }),
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
