<?php

namespace App\Filament\Mms\Actions\Leave;

use App\Filament\Mms\Concerns\PayrollRefresh;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestPeriod;
use App\Services\MMS\LeaveRequestService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use RuntimeException;

/**
 * An approved request's days classified again, with the approval's own form filled with how it was approved —
 * annual leave that turns out to be a new baby's or a death in the family: those days back in the balance, the
 * payroll's absence ledger and the entitlements written again (LeaveRequestService::reclassify()).
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
            ->modalDescription(__('Classify the approved days again: annual days changed to another type go back to the balance. A payroll already generated for those days needs generating again.'))
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
}
