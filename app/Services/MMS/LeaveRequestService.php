<?php

namespace App\Services\MMS;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestPeriod;
use App\Models\PartyLeave;
use App\Models\User;
use App\Services\Notify\UserAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Approval and rejection of leave requests.
 *
 * The employee asks for days; the approver decides what those days ARE. That
 * division is the point of this class: an employee cannot see their own sick
 * ladder or annual balance, so asking them to classify their own absence would
 * be asking them to guess at a figure that determines their pay. The split
 * arrives here at approval time, from the approver.
 *
 * Approving is also what makes a request real to the rest of the app: it writes
 * rows into `party_leaves`, the ledger the incentive calculator already prorates
 * quotas from and the payroll engine counts unpaid days from. Neither of those
 * knows this workflow exists, which is the point — a request sitting in the
 * queue must have no effect on anyone's pay or quota until someone approves it.
 */
class LeaveRequestService
{
    public function __construct(
        private readonly LeaveEntitlementService $entitlements,
        private readonly LeaveBalanceService $balances,
    ) {}

    /**
     * Approve a request, recording how the absence divides.
     *
     * `$approver` is nullable so an office email's one-click approve link — the
     * signature on that URL is the authentication, there is no logged-in user to
     * attribute it to — can still call this. `approved_by` is left null in that
     * case; the comment says how it was decided instead.
     *
     * @param  list<array{leave_type: string|LeaveType, start_date: string, end_date: string, day_count: float|string}>  $periods
     */
    public function approve(
        LeaveRequest $request,
        ?User $approver,
        array $periods,
        ?string $comment = null,
    ): LeaveRequest {
        if (! $request->isPending()) {
            throw new RuntimeException('Only a pending leave request can be approved.');
        }

        $this->assertPeriodsCoverTheRequest($request, $periods);
        $this->assertAnnualWithinBalance($request, $periods);

        return DB::transaction(function () use ($request, $approver, $periods, $comment): LeaveRequest {
            $request->forceFill([
                'status' => RequestStatus::APPROVED,
                'approved_by' => $approver?->getKey(),
                'approved_at' => now(),
                'approved_comment' => $comment,
            ])->save();

            // Replaced wholesale rather than merged. A request approved twice is
            // already refused above, so the only way rows exist here is a earlier
            // draft split, and keeping them would double the days.
            $request->periods()->delete();

            foreach ($periods as $period) {
                $saved = LeaveRequestPeriod::create([
                    'leave_request_id' => $request->getKey(),
                    'leave_type' => $period['leave_type'],
                    'start_date' => $period['start_date'],
                    'end_date' => $period['end_date'],
                    'day_count' => $period['day_count'],
                ]);

                $this->publishToLedger($request, $saved);
                $this->recordAgainstEntitlement($request, $saved);
                $this->balances->recordTaken($request, $saved);
            }

            DB::afterCommit(fn () => $this->tellRequester($request, approved: true));

            return $request->load('periods');
        });
    }

    /**
     * Reject a request, recording why.
     *
     * The reason is required. A rejection with no explanation is the single most
     * common complaint about approval queues, and the column exists precisely so
     * the employee can be told something.
     */
    public function reject(LeaveRequest $request, ?User $approver, string $reason): LeaveRequest
    {
        if (! $request->isPending()) {
            throw new RuntimeException('Only a pending leave request can be rejected.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A rejection must state a reason.');
        }

        return DB::transaction(function () use ($request, $approver, $reason): LeaveRequest {
            $request->forceFill([
                'status' => RequestStatus::REJECTED,
                'approved_by' => $approver?->getKey(),
                'approved_at' => now(),
                'approved_comment' => $reason,
            ])->save();

            // Belt and braces: nothing should have reached the ledger from a
            // pending request, but if anything did, a rejection must retract it.
            PartyLeave::whereIn(
                'leave_request_period_id',
                $request->periods()->pluck('id'),
            )->delete();

            DB::afterCommit(fn () => $this->tellRequester($request, approved: false));

            return $request;
        });
    }

    /**
     * The employee hears the decision in the system — bell, desktop, phone —
     * however it was made (the panel or the office email's one-click link).
     */
    private function tellRequester(LeaveRequest $request, bool $approved): void
    {
        $period = __(':start to :end', [
            'start' => $request->start_date?->format('d/m/Y') ?? '—',
            'end' => $request->end_date?->format('d/m/Y') ?? '—',
        ]);

        UserAlert::send(
            $request->party?->user,
            $approved ? __('Leave request approved') : __('Leave request rejected'),
            $approved
                ? __('Your leave request for :period was approved.', ['period' => $period])
                : __('Your leave request for :period was rejected: :reason', ['period' => $period, 'reason' => (string) $request->approved_comment]),
            Route::has('filament.mms.resources.leave-requests.index') ? route('filament.mms.resources.leave-requests.index') : null,
            $approved ? 'success' : 'danger',
        );
    }

    /**
     * A starting split for the approver. Annual leave (the default when the
     * employee stated no type) comes out of the balance while it lasts and
     * the rest is unpaid — all of it unpaid with no balance left. Any other
     * type is the whole absence as one block of that type.
     *
     * Offered as a default rather than applied automatically. It is right often
     * enough to save typing and visible enough that changing it is obviously the
     * approver's job — except via an office email's one-click approve link,
     * which has no form to change it in and adopts this suggestion outright.
     *
     * @return list<array{leave_type: string, start_date: string, end_date: string, day_count: float}>
     */
    public function suggestSplit(LeaveRequest $request, ?LeaveType $type = null): array
    {
        $type ??= $request->getAttribute('requested_leave_type') ?? LeaveType::ANNUAL;

        if ($type === LeaveType::ANNUAL) {
            return $this->balances->suggestSplit($request);
        }

        return [[
            'leave_type' => $type->value,
            'start_date' => $request->getAttribute('start_date')->toDateString(),
            'end_date' => $request->getAttribute('end_date')->toDateString(),
            'day_count' => $request->requestedDays(),
        ]];
    }

    /**
     * The split must describe the absence that was actually requested.
     *
     * Without this an approver could book thirty unpaid days against a request
     * for two, or approve a request with no periods at all — which would set the
     * status to approved while writing nothing to the leave ledger, leaving an
     * absence that shows as granted and costs nothing.
     *
     * @param  list<array{leave_type: string|LeaveType, start_date: string, end_date: string, day_count: float|string}>  $periods
     */
    private function assertPeriodsCoverTheRequest(LeaveRequest $request, array $periods): void
    {
        if ($periods === []) {
            throw new RuntimeException('Approving a request means saying what kind of leave it is; add at least one period.');
        }

        $start = $request->getAttribute('start_date')->startOfDay();
        $end = $request->getAttribute('end_date')->endOfDay();
        $total = 0.0;

        foreach ($periods as $period) {
            $from = Carbon::parse($period['start_date'])->startOfDay();
            $to = Carbon::parse($period['end_date'])->endOfDay();

            if ($from->lessThan($start) || $to->greaterThan($end)) {
                throw new RuntimeException('Every period must fall inside the dates the employee asked for.');
            }

            if ($to->lessThan($from)) {
                throw new RuntimeException('A period cannot end before it starts.');
            }

            $total += (float) $period['day_count'];
        }

        if ($total > $request->requestedDays()) {
            throw new RuntimeException(sprintf(
                'The split adds up to %s days, more than the %s days requested.',
                rtrim(rtrim(number_format($total, 1), '0'), '.'),
                rtrim(rtrim(number_format($request->requestedDays(), 1), '0'), '.'),
            ));
        }
    }

    /**
     * Annual days can only come out of what the balance holds; anything
     * beyond it is unpaid leave, and has to be booked as such.
     *
     * @param  list<array{leave_type: string|LeaveType, start_date: string, end_date: string, day_count: float|string}>  $periods
     */
    private function assertAnnualWithinBalance(LeaveRequest $request, array $periods): void
    {
        $annual = 0.0;

        foreach ($periods as $period) {
            $type = $period['leave_type'] instanceof LeaveType ? $period['leave_type'] : LeaveType::tryFrom((string) $period['leave_type']);

            if ($type === LeaveType::ANNUAL) {
                $annual += (float) $period['day_count'];
            }
        }

        $balance = $this->balances->balance((int) $request->getAttribute('party_id'));

        if ($annual > 0 && $annual > $balance + 0.001) {
            throw new RuntimeException(__('Only :balance annual leave days are left in the balance; book the rest as unpaid leave.', [
                'balance' => rtrim(rtrim(number_format(max(0, $balance), 1), '0'), '.'),
            ]));
        }
    }

    /**
     * Mirror one approved period into `party_leaves`.
     */
    private function publishToLedger(LeaveRequest $request, LeaveRequestPeriod $period): PartyLeave
    {
        return PartyLeave::updateOrCreate(
            ['leave_request_period_id' => $period->getKey()],
            [
                'party_id' => $request->getAttribute('party_id'),
                'start_date' => $period->getAttribute('start_date'),
                'end_date' => $period->getAttribute('end_date'),
                'leave_type' => $period->getAttribute('leave_type'),
                'pay_factor' => $period->getAttribute('pay_factor'),
                'reason' => $request->getAttribute('comment'),
            ],
        );
    }

    /**
     * Draw the period's days down from the relevant balance.
     */
    private function recordAgainstEntitlement(LeaveRequest $request, LeaveRequestPeriod $period): void
    {
        $type = $period->getAttribute('leave_type');

        $column = match ($type) {
            LeaveType::ANNUAL => 'annual_taken_days',
            LeaveType::SICK_FULL => 'sick_full_taken',
            LeaveType::SICK_HALF => 'sick_half_taken',
            LeaveType::SICK_UNPAID => 'sick_unpaid_taken',
            // Casual, unpaid and unauthorised absence draw on no balance: the
            // first is granted at the office's discretion, the other two are
            // already their own penalty.
            default => null,
        };

        if ($column === null) {
            return;
        }

        $entitlement = $this->entitlements->forDate(
            $request->party,
            $period->getAttribute('start_date'),
        );

        $entitlement->increment($column, (float) $period->getAttribute('day_count'));
    }
}
