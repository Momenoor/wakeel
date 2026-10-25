<?php

namespace App\Services\MMS;

use App\Models\Fee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who entered each fee, put back: a fee kept whoever saved it last (a payment recorded on it, a change) until
 * it kept whoever added it — the activity log says who that was (its "created" entry). A fee with no such
 * entry (imported, or from before the log) keeps what it has. Each change written in a log; nothing changed but
 * the log for a preview.
 */
class FeeEnteredByRestore
{
    /**
     * @return array{file: string, fees: int, unknown: int}
     */
    public function run(bool $apply): array
    {
        // Each fee's first "created" entry naming who — all at once.
        $first = DB::table('activity_log')
            ->where('subject_type', Fee::class)
            ->where('event', 'created')
            ->whereNotNull('causer_id')
            ->selectRaw('subject_id, MIN(id) as entry')
            ->groupBy('subject_id');

        $changes = DB::table('activity_log as created')
            ->joinSub($first, 'first', 'first.entry', '=', 'created.id')
            ->join('fees', 'fees.id', '=', 'created.subject_id')
            ->where(fn ($query) => $query->whereNull('fees.user_id')->orWhereColumn('fees.user_id', '!=', 'created.causer_id'))
            ->orderBy('fees.id')
            ->get(['fees.id', 'fees.matter_id', 'fees.type', 'fees.amount', 'fees.user_id', 'created.causer_id']);

        $names = User::query()->pluck('name', 'id');
        $name = fn (?int $id): string => $id === null ? '—' : ($names[$id] ?? '#'.$id);
        $ref = FeeFixLog::references();
        $unknown = DB::table('fees')->whereNotIn('id', DB::table('activity_log')->where('subject_type', Fee::class)->where('event', 'created')->whereNotNull('causer_id')->select('subject_id'))->count();

        $log = FeeFixLog::header('who entered each fee, from the activity log', $apply);
        $log[] = 'Fees whose "entered by" is put back to who added them: '.$changes->count();
        foreach ($changes as $fee) {
            $log[] = sprintf('   Fee #%d (matter %s, %s, %s): %s → %s', $fee->id, $ref((int) $fee->matter_id), $fee->type ?? '—', FeeFixLog::money($fee->amount), $name($fee->user_id), $name((int) $fee->causer_id));
        }
        $log[] = '';
        $log[] = "Fees with no record of who added them (imported, or from before the activity log): {$unknown} — kept as they are.";

        if ($apply) {
            // One update for each person: their fees.
            $changes->groupBy('causer_id')->each(fn ($fees, $userId) => DB::table('fees')->whereIn('id', $fees->pluck('id'))->update(['user_id' => (int) $userId]));
        }

        return ['file' => FeeFixLog::save('entered-by', $apply, $log), 'fees' => $changes->count(), 'unknown' => $unknown];
    }
}
