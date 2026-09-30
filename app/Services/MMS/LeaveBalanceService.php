<?php

namespace App\Services\MMS;

use App\Enums\LeaveBalanceEntryKind;
use App\Enums\LeaveType;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalanceEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestPeriod;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The annual-leave balance, as a running record (leave_balance_entries).
 *
 * It starts from each employee's opening balance, gains the annual days
 * every 1 January (and a pro-rated share in the year someone joins), and
 * loses the annual days of every approved request. Unused days carry over.
 *
 * Sick leave is not here: its ladder still runs per year of service
 * (LeaveEntitlementService).
 */
class LeaveBalanceService
{
    public function __construct(
        private readonly LeaveEntitlementService $entitlements,
    ) {}

    /**
     * The employee's balance now, in days.
     */
    public function balance(int $partyId): float
    {
        return round((float) LeaveBalanceEntry::where('party_id', $partyId)->sum('days'), 1);
    }

    /**
     * The day tracking began — grants are only given after it: the date of
     * the first opening balance (written for everyone when tracking was
     * installed). A `leave_balance_started_on` setting overrides it.
     */
    public function startedOn(): CarbonImmutable
    {
        $started = Setting::get('leave_balance_started_on')
            ?? LeaveBalanceEntry::where('kind', LeaveBalanceEntryKind::OPENING)->min('entry_date');

        return $started ? CarbonImmutable::parse($started)->startOfDay() : CarbonImmutable::today();
    }

    /**
     * Keep the "Opening balance" entry in step with the profile's field.
     */
    public function syncOpening(EmployeeProfile $profile): void
    {
        $partyId = $profile->getAttribute('party_id');

        if ($partyId === null) {
            return;
        }

        $days = (float) ($profile->getAttribute('opening_leave_balance') ?? 0);
        $entry = LeaveBalanceEntry::where('party_id', $partyId)->where('kind', LeaveBalanceEntryKind::OPENING)->first();

        if ($entry === null) {
            LeaveBalanceEntry::create([
                'party_id' => $partyId,
                'kind' => LeaveBalanceEntryKind::OPENING,
                'days' => $days,
                'entry_date' => now()->toDateString(),
            ]);

            return;
        }

        if ((float) $entry->getAttribute('days') !== $days) {
            $entry->update(['days' => $days]);
        }
    }

    /**
     * The split a request gets by default: annual days while the balance
     * lasts (whole days, from the first day), the rest unpaid. With no
     * balance the whole request is unpaid.
     *
     * @return list<array{leave_type: string, start_date: string, end_date: string, day_count: float}>
     */
    public function suggestSplit(LeaveRequest $request): array
    {
        $start = CarbonImmutable::parse($request->getAttribute('start_date'))->startOfDay();
        $end = CarbonImmutable::parse($request->getAttribute('end_date'))->startOfDay();
        $requested = (int) $request->requestedDays();
        $annual = (int) min($requested, max(0, floor($this->balance((int) $request->getAttribute('party_id')))));

        $split = [];

        if ($annual > 0) {
            $split[] = [
                'leave_type' => LeaveType::ANNUAL->value,
                'start_date' => $start->toDateString(),
                'end_date' => $start->addDays($annual - 1)->toDateString(),
                'day_count' => (float) $annual,
            ];
        }

        if ($requested > $annual) {
            $split[] = [
                'leave_type' => LeaveType::UNPAID->value,
                'start_date' => $start->addDays($annual)->toDateString(),
                'end_date' => $end->toDateString(),
                'day_count' => (float) ($requested - $annual),
            ];
        }

        return $split;
    }

    /**
     * Take an approved period's annual days off the balance.
     */
    public function recordTaken(LeaveRequest $request, LeaveRequestPeriod $period): void
    {
        if ($period->getAttribute('leave_type') !== LeaveType::ANNUAL) {
            return;
        }

        LeaveBalanceEntry::updateOrCreate(
            ['leave_request_period_id' => $period->getKey()],
            [
                'party_id' => $request->getAttribute('party_id'),
                'kind' => LeaveBalanceEntryKind::TAKEN,
                'days' => -1 * (float) $period->getAttribute('day_count'),
                'entry_date' => $period->getAttribute('start_date'),
            ],
        );
    }

    /**
     * Give the grants due by a date: this year's 1 January grant to everyone
     * employed on 1 January, and the pro-rated grant for anyone who joined
     * this year. Safe to run any number of times.
     *
     * @return int the number of grants given
     */
    public function grantDue(CarbonInterface $today): int
    {
        $year = (int) $today->year;
        $started = $this->startedOn();
        $given = 0;

        foreach (EmployeeProfile::query()->whereNotNull('party_id')->get() as $profile) {
            $joined = $profile->getAttribute('date_of_joining');
            $left = $profile->getAttribute('date_of_leaving');
            $newYear = CarbonImmutable::create($year);

            // 1 January: employed that day, and the year after tracking began
            // (the opening balance already covers the year it began in).
            if ($year > $started->year
                && ($joined === null || $joined->lessThan($newYear))
                && ($left === null || $left->greaterThanOrEqualTo($newYear))) {
                $given += $this->grant($profile, LeaveBalanceEntryKind::ANNUAL_GRANT, $year, $this->entitlements->annualDays(), $newYear);
            }

            // Joined this year, after tracking began: the months left in it.
            if ($joined !== null
                && (int) $joined->year === $year
                && $joined->greaterThanOrEqualTo($started)
                && $joined->lessThanOrEqualTo($today)) {
                $given += $this->grant($profile, LeaveBalanceEntryKind::JOINING_GRANT, $year, self::proRated($this->entitlements->annualDays(), $joined), $joined);
            }
        }

        return $given;
    }

    /**
     * A year's days for the months left in it, counting the joining month:
     * joining in March leaves 10 months — 25 of 30 days. To the half day.
     */
    public static function proRated(float $annualDays, CarbonInterface $joined): float
    {
        $months = 12 - (int) $joined->month + 1;

        return round($annualDays / 12 * $months * 2) / 2;
    }

    private function grant(EmployeeProfile $profile, LeaveBalanceEntryKind $kind, int $year, float $days, CarbonInterface $on): int
    {
        $entry = LeaveBalanceEntry::firstOrCreate(
            ['party_id' => $profile->getAttribute('party_id'), 'kind' => $kind, 'year' => $year],
            ['days' => $days, 'entry_date' => $on->toDateString()],
        );

        return $entry->wasRecentlyCreated ? 1 : 0;
    }
}
