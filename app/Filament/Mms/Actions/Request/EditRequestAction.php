<?php

namespace App\Filament\Mms\Actions\Request;

use App\Enums\MatterDifficulty;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\MatterRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * A request's record corrected by the super admin: who asked, what was written, what is asked for
 * while it is still to be decided, its status (approved or rejected) and — once decided — who
 * decided, when and their note. The record only: nothing is applied to the matter or taken back
 * from it, and nobody is told — that is what the Approve and Reject buttons do.
 */
class EditRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'edit_request';
    }

    public function setUp(): void
    {
        parent::setUp();
        $this
            ->label(__('Edit'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => self::allowed())
            ->modalHeading(__('Edit request'))
            ->modalDescription(__('Corrects what the request records. Changing the status here changes the record only: nothing is applied to the matter or undone on it, and nobody is notified. Use Approve or Reject for that.'))
            ->fillForm(fn (MatterRequest $record): array => [
                'status' => $record->status?->value,
                'request_by' => $record->request_by,
                'comment' => $record->comment,
                'new_difficulty' => $record->extra['new_difficulty'] ?? null,
                'proposed_distributed_at' => $record->extra['proposed_distributed_at'] ?? null,
                'approved_by' => $record->approved_by,
                'approved_at' => $record->approved_at,
                'approved_comment' => $record->approved_comment,
            ])
            ->successNotificationTitle(__('Saved'))
            ->action(function (MatterRequest $record, array $data): void {
                abort_unless(self::allowed(), 403);

                $record->update(self::changes($record, $data));
                $this->success();
            });
    }

    public function getSchema(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->label(__('Status'))
                // Approved or rejected — and what it is now, while it is neither.
                ->options(fn (MatterRequest $record): array => collect([$record->status, RequestStatus::APPROVED, RequestStatus::REJECTED])
                    ->filter()
                    ->mapWithKeys(fn (RequestStatus $status): array => [$status->value => $status->getLabel()])
                    ->all())
                ->selectablePlaceholder(false)
                ->required()
                ->live(),
            Select::make('request_by')
                ->label(__('Request By'))
                ->options(fn (): array => self::users())
                ->searchable()
                ->placeholder(__('The system')),
            Textarea::make('comment')->label(__('Comment'))->rows(3),
            Select::make('new_difficulty')
                ->label(__('New Difficulty'))
                ->options(MatterDifficulty::class)
                ->required()
                ->visible(fn (MatterRequest $record): bool => self::asks($record, RequestType::CHANGE_DIFFICULTY, 'new_difficulty')),
            DatePicker::make('proposed_distributed_at')
                ->label(__('Proposed Assistant Assigning Date'))
                ->required()
                ->visible(fn (MatterRequest $record): bool => self::asks($record, RequestType::CHANGE_DISTRIBUTED_DATE, 'proposed_distributed_at')),
            Select::make('approved_by')
                ->label(__('Handled By'))
                ->options(fn (): array => self::users())
                ->searchable()
                ->visible(fn (Get $get): bool => self::decided($get('status'))),
            DateTimePicker::make('approved_at')
                ->label(__('Handled At'))
                ->seconds(false)
                ->visible(fn (Get $get): bool => self::decided($get('status'))),
            Textarea::make('approved_comment')
                ->label(__('Handling Note'))
                ->rows(2)
                ->visible(fn (Get $get): bool => self::decided($get('status'))),
        ]);
    }

    private static function allowed(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /** Approved or rejected: its decision can be corrected. */
    private static function decided(RequestStatus|string|null $status): bool
    {
        $status = is_string($status) ? RequestStatus::tryFrom($status) : $status;

        return in_array($status, [RequestStatus::APPROVED, RequestStatus::REJECTED], true);
    }

    /** Still to be decided, of the type that asks for this value, and asking for one. */
    private static function asks(MatterRequest $record, RequestType $type, string $key): bool
    {
        return $record->type === $type && ! self::decided($record->status) && filled($record->extra[$key] ?? null);
    }

    /**
     * What the form gave, as the request keeps it — only the fields it showed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function changes(MatterRequest $record, array $data): array
    {
        $changes = ['request_by' => $data['request_by'] ?? null, 'comment' => $data['comment'] ?? null];

        $extra = (array) $record->extra;
        if (self::asks($record, RequestType::CHANGE_DIFFICULTY, 'new_difficulty') && filled($data['new_difficulty'] ?? null)) {
            $difficulty = $data['new_difficulty'];
            $extra['new_difficulty'] = $difficulty instanceof MatterDifficulty ? $difficulty->value : (string) $difficulty;
        }
        if (self::asks($record, RequestType::CHANGE_DISTRIBUTED_DATE, 'proposed_distributed_at') && filled($data['proposed_distributed_at'] ?? null)) {
            $extra['proposed_distributed_at'] = $data['proposed_distributed_at'];
        }
        if ($extra !== (array) $record->extra) {
            $changes['extra'] = $extra;
        }

        $status = RequestStatus::tryFrom((string) ($data['status'] ?? '')) ?? $record->status;
        $changes['status'] = $status;

        if (self::decided($status)) {
            // Decided here for the first time: by whoever is editing, now — unless said otherwise.
            $changes += [
                'approved_by' => ($data['approved_by'] ?? null) ?: auth()->id(),
                'approved_at' => ($data['approved_at'] ?? null) ?: now(),
                'approved_comment' => $data['approved_comment'] ?? null,
            ];
        }

        return $changes;
    }

    /**
     * @return array<int, string>
     */
    private static function users(): array
    {
        // Read once for the form's two lists.
        return once(fn (): array => User::query()->orderBy('name')->get(['id', 'name', 'display_name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => (string) ($user->display_name ?: $user->name)])
            ->all());
    }
}
