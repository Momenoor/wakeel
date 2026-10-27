<?php

namespace App\Filament\Mms\Actions\Leave;

use App\Enums\LeaveType;
use App\Filament\Mms\Concerns\PayrollRefresh;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestPeriod;
use App\Services\MMS\LeaveRequestService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use RuntimeException;

/**
 * An approved request's days classified again, with the approval's own form filled with how it was approved —
 * annual leave that turns out to be unpaid, say: the balance following the new types (days now of a type not taken
 * off it back in it), the payroll's absence ledger and the entitlements written again (LeaveRequestService::reclassify()).
 */
class ChangeLeaveTypesAction extends ApproveLeaveAction
{
    public static function getDefaultName(): ?string
    {
        return 'change_leave_types';
    }

    public function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Change leave types'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            ->modalHeading(__('Change leave types'))
            ->modalDescription(__('Classify the approved days again: the balance follows the new types, days now of a type not taken off it going back to it. A payroll already generated for those days needs generating again.'))
            ->modalSubmitActionLabel(__('Save'))
            ->authorize('approve')
            ->visible(fn (LeaveRequest $record): bool => $record->isApproved())
            ->fillForm(fn (LeaveRequest $record): array => [
                'periods' => $record->periods->map(fn (LeaveRequestPeriod $period): array => [
                    'leave_type' => $period->leave_type?->value,
                    'start_date' => $period->start_date?->toDateString(),
                    'end_date' => $period->end_date?->toDateString(),
                    'day_count' => (float) $period->day_count,
                ])->values()->all(),
            ])
            ->action(function (LeaveRequest $record, array $data, Action $action, $livewire): void {
                try {
                    app(LeaveRequestService::class)->reclassify($record, auth()->user(), array_values($data['periods'] ?? []), $data['approved_comment'] ?? null);
                } catch (RuntimeException $exception) {
                    Notification::make()->danger()->title(__('Could not change the leave types'))->body($exception->getMessage())->send();

                    $action->halt();
                }

                Notification::make()->success()->title(__('Leave types changed.'))->send();
                $livewire->dispatch(PayrollRefresh::EVENT);
            });
    }

    /**
     * With the days it took off the balance already, given back when its types change.
     */
    protected static function annualAvailable(LeaveRequest $record): float
    {
        return parent::annualAvailable($record) + (float) $record->periods->filter(fn ($period): bool => LeaveType::fromBalance($period->leave_type))->sum('day_count');
    }
}
