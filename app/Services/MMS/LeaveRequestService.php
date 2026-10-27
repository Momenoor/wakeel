<?php

namespace App\Services\MMS;

use App\Enums\LeaveType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\RequestStatus;
use App\Mail\LeaveRequestDecisionMail;
use App\Models\LeaveBalanceEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestPeriod;
use App\Models\PartyLeave;
use App\Models\User;
use App\Observers\LeaveRequestObserver;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\Notifier;
use App\Support\Addresses;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

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
 * rows into `party_leaves`, the ledger the incentive calculator leaves out of
 * completion days and the payroll engine counts unpaid days from. Neither of those
 * knows this workflow exists, which is the point — a request sitting in the
 * queue must have no effect on anyone's pay or incentive until someone approves it.
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

        // Annual days beyond the balance are the approver's to give: the balance goes below zero and the next
        // grant makes them up. (The request itself only suggests annual days within it — LeaveBalanceService::suggestSplit().)
        $this->assertPeriodsCoverTheRequest($request, $periods);

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
     * An approved request's days classified again — annual leave that turns out to be a new baby's, or a death in
     * the family: the periods it was approved with undone everywhere they were written (the absence ledger payroll
     * reads, the entitlement counters, the annual balance) and the new ones written as an approval writes them. Its
     * annual days given back to the balance, those now annual taken; said on the request, by whom and when.
     *
     * @param  list<array{leave_type: string|LeaveType, start_date: string, end_date: string, day_count: float|string}>  $periods
     */
    public function reclassify(LeaveRequest $request, ?User $by, array $periods, ?string $comment = null): LeaveRequest
    {
        if ($request->getAttribute('status') !== RequestStatus::APPROVED) {
            throw new RuntimeException('Only an approved leave request can have its leave types changed.');
        }

        $this->assertPeriodsCoverTheRequest($request, $periods);
        $old = $request->periods()->get();

        return DB::transaction(function () use ($request, $by, $periods, $comment, $old): LeaveRequest {
            foreach ($old as $period) {
                $this->recordAgainstEntitlement($request, $period, release: true);
            }
            PartyLeave::query()->whereIn('leave_request_period_id', $old->modelKeys())->delete();
            LeaveBalanceEntry::query()->whereIn('leave_request_period_id', $old->modelKeys())->delete();
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

            $note = __('Leave types changed on :date by :user', [
                'date' => now()->format(Dates::dateTimeFormat()),
                'user' => $by?->display_name ?: ($by?->name ?? '—'),
            ]).(filled($comment) ? ': '.$comment : '.');
            $request->forceFill(['approved_comment' => trim(((string) $request->getAttribute('approved_comment'))."\n".$note)])->save();

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
     * The employee hears the decision however it was made (the panel or the office email's one-click
     * link), by what they chose (NotificationType::LEAVE_DECIDED): the bell — with the desktop and phone
     * notifications — the decision email, WhatsApp. One with no user has the email. The office it was
     * announced to is copied in on it (to them, when the employee isn't sent it).
     */
    private function tellRequester(LeaveRequest $request, bool $approved): void
    {
        $user = $request->party?->user;

        if (! $user) {
            $this->mailDecision($request, Addresses::emails((array) ($request->party?->email ?? [])));

            return;
        }

        $period = __(':start to :end', [
            'start' => $request->start_date?->format(Dates::dateFormat()) ?? '—',
            'end' => $request->end_date?->format(Dates::dateFormat()) ?? '—',
        ]);
        $status = $approved ? 'success' : 'danger';
        $type = NotificationType::LEAVE_DECIDED;

        Notifier::send(
            $type,
            [$user],
            $approved ? __('Leave request approved') : __('Leave request rejected'),
            $approved
                ? __('Your leave request for :period was approved.', ['period' => $period])
                : __('Your leave request for :period was rejected: :reason', ['period' => $period, 'reason' => (string) $request->approved_comment]),
            // The request itself, for whoever may open it; the list otherwise.
            match (true) {
                $user->can('update', $request) && Route::has('filament.mms.resources.leave-requests.edit') => route('filament.mms.resources.leave-requests.edit', $request),
                Route::has('filament.mms.resources.leave-requests.index') => route('filament.mms.resources.leave-requests.index'),
                default => null,
            },
            fn (Notification $notification) => $notification->status($status)->iconColor($status),
            [NotificationChannel::BELL, NotificationChannel::WHATSAPP],
        );

        // To the address the employee gave as a party, else their user's.
        $this->mailDecision($request, NotificationPreferences::wants($user, $type, NotificationChannel::MAIL)
            ? Addresses::emails(filled($request->party->email) ? (array) $request->party->email : [$user->email])
            : []);
    }

    /**
     * The decision email: to the employee, the office copied in — one email, each seeing who has it.
     *
     * @param  list<string>  $employee
     */
    private function mailDecision(LeaveRequest $request, array $employee): void
    {
        $office = Addresses::without(LeaveRequestObserver::mailed(), $employee);

        if ($employee === [] && $office === []) {
            return;
        }

        try {
            Mail::to($employee !== [] ? $employee : $office)
                ->cc($employee !== [] ? $office : [])
                ->locale('ar')
                ->queue(new LeaveRequestDecisionMail($request));
        } catch (Throwable $e) {
            report($e);
        }
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

        // Sick leave falls across its pay bands in order (full, half, unpaid, then ordinary unpaid) from what
        // is left of them this service year — as the email's one-click approve books it too.
        if (in_array($type, [LeaveType::SICK_FULL, LeaveType::SICK_HALF, LeaveType::SICK_UNPAID], true) && $request->party) {
            return $this->sickSplit($request);
        }

        return [[
            'leave_type' => $type->value,
            'start_date' => $request->getAttribute('start_date')->toDateString(),
            'end_date' => $request->getAttribute('end_date')->toDateString(),
            'day_count' => $request->requestedDays(),
        ]];
    }

    /**
     * The sick days requested, band by band, each its own run of days in order; the last ends on the request's
     * last day.
     *
     * @return list<array{leave_type: string, start_date: string, end_date: string, day_count: float}>
     */
    private function sickSplit(LeaveRequest $request): array
    {
        $start = CarbonImmutable::parse($request->getAttribute('start_date'))->startOfDay();
        $end = CarbonImmutable::parse($request->getAttribute('end_date'))->startOfDay();
        $bands = $this->entitlements->splitSickDays($this->entitlements->forDate($request->party, $start), $request->requestedDays());
        $split = [];

        foreach ($bands as $i => $band) {
            $last = $i === array_key_last($bands);
            $until = $last ? $end : $start->addDays((int) ceil($band['day_count']) - 1);

            $split[] = [
                'leave_type' => $band['leave_type']->value,
                'start_date' => $start->toDateString(),
                'end_date' => $until->toDateString(),
                'day_count' => (float) $band['day_count'],
            ];

            $start = $until->addDay();
        }

        return $split;
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
     * Draw the period's days down from the relevant balance — or, $release, give them back (its types changed).
     */
    private function recordAgainstEntitlement(LeaveRequest $request, LeaveRequestPeriod $period, bool $release = false): void
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

        $release
            ? $entitlement->decrement($column, min((float) $period->getAttribute('day_count'), (float) $entitlement->getAttribute($column)))
            : $entitlement->increment($column, (float) $period->getAttribute('day_count'));
    }
}
