<?php

namespace App\Filament\Mms\Actions\Leave;

use App\Filament\Mms\Concerns\PayrollRefresh;
use App\Models\LeaveRequest;
use App\Services\MMS\LeaveBalanceService;
use App\Services\MMS\LeaveRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * An approved leave not taken in full: ended early on the last day taken, or cancelled when none of it was — the
 * days not taken back in the balance, and the absence ledger the calendar, the incentive and payroll read put right
 * (LeaveRequestService::endEarly(), cancel()).
 */
class EndLeaveEarlyAction extends Action
{
    public const END_EARLY = 'end_early';

    public const CANCEL = 'cancel';

    public static function getDefaultName(): ?string
    {
        return 'end_leave_early';
    }

    public function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('End early / Cancel'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->modalHeading(__('End the leave early, or cancel it'))
            ->modalDescription(__('The days not taken go back to the balance, and come off the vacation calendar, the incentive and payroll. A payroll already generated for those days needs generating again.'))
            ->modalSubmitActionLabel(__('Save'))
            ->authorize('approve')
            ->visible(fn (LeaveRequest $record): bool => $record->isApproved())
            ->fillForm(fn (): array => ['mode' => self::END_EARLY])
            ->action(function (LeaveRequest $record, array $data, Action $action, $livewire): void {
                $service = app(LeaveRequestService::class);
                $balances = app(LeaveBalanceService::class);
                $before = $balances->balance((int) $record->party_id);

                try {
                    $data['mode'] === self::CANCEL
                        ? $service->cancel($record, auth()->user(), $data['reason'] ?? null)
                        : $service->endEarly($record, auth()->user(), Carbon::parse($data['last_day']), $data['reason'] ?? null);
                } catch (RuntimeException $exception) {
                    Notification::make()->danger()->title(__('Could not continue'))->body($exception->getMessage())->send();

                    $action->halt();
                }

                $back = $balances->balance((int) $record->party_id) - $before;

                Notification::make()
                    ->success()
                    ->title($data['mode'] === self::CANCEL ? __('Leave cancelled.') : __('Leave ended early.'))
                    ->body($back > 0 ? __(':days days back to the balance.', ['days' => rtrim(rtrim(number_format($back, 1), '0'), '.')]) : null)
                    ->send();

                $livewire->dispatch(PayrollRefresh::EVENT);
            });
    }

    public function getSchema(Schema $schema): Schema
    {
        return $schema->components([
            Radio::make('mode')
                ->hiddenLabel()
                ->options([
                    self::END_EARLY => __('Came back early: some of it was taken'),
                    self::CANCEL => __('Cancel it: none of it was taken'),
                ])
                ->required()
                ->live(),
            DatePicker::make('last_day')
                ->label(__('Last day of leave taken'))
                ->helperText(__('Back at work the day after.'))
                ->visible(fn (Get $get): bool => $get('mode') === self::END_EARLY)
                ->required(fn (Get $get): bool => $get('mode') === self::END_EARLY)
                ->minDate(fn (?LeaveRequest $record) => $record?->start_date)
                ->maxDate(fn (?LeaveRequest $record) => $record?->end_date?->copy()->subDay()),
            Textarea::make('reason')
                ->label(__('Reason'))
                ->rows(2),
        ]);
    }
}
