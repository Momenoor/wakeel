<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The employee's Leave Balance lists every leave, whatever its type: the ones whose type takes days off the balance
 * already have their entry; the others (sick, unpaid, absence, casual when Settings → Payroll leaves it out) get one with
 * no days, so the balance is unchanged. Only vacations that have a type (a request's always do).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('party_leaves')
            ->whereNotNull('party_leaves.leave_type')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('leave_balance_entries')
                ->where(fn ($on) => $on->whereColumn('leave_balance_entries.leave_request_period_id', 'party_leaves.leave_request_period_id')
                    ->orWhereColumn('leave_balance_entries.party_leave_id', 'party_leaves.id')))
            ->orderBy('party_leaves.id')
            ->select(['party_leaves.id', 'party_leaves.party_id', 'party_leaves.leave_request_period_id', 'party_leaves.start_date'])
            ->each(function (object $leave) use ($now): void {
                $byRequest = $leave->leave_request_period_id !== null;

                DB::table('leave_balance_entries')->insert([
                    'party_id' => $leave->party_id,
                    'kind' => 'taken',
                    'days' => 0,
                    'entry_date' => $leave->start_date,
                    'year' => null,
                    'leave_request_period_id' => $byRequest ? $leave->leave_request_period_id : null,
                    'party_leave_id' => $byRequest ? null : $leave->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('leave_balance_entries')->where('kind', 'taken')->where('days', 0)->delete();
    }
};
