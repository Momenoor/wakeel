<?php

namespace App\Services\MMS;

use App\Models\Party;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who a matter's news goes to: the users of its experts — or its assistants only — and the administrators
 * who handle those assistants' matters with them (Users → Edit → Assistant), and the super admins when
 * asked. Read in one query, whatever the number of matters.
 */
final class MatterPeople
{
    /**
     * @param  list<int>  $matterIds
     * @return Collection<int, User>
     */
    public static function of(array $matterIds, bool $superAdmins = false, bool $assistantsOnly = false): Collection
    {
        $parties = self::parties($matterIds, $assistantsOnly);

        return User::query()
            ->where(fn (Builder $q) => $q
                ->whereIn('id', Party::query()->whereIn('id', $parties)->whereNotNull('user_id')->select('user_id'))
                ->orWhereIn('id', DB::table('assistant_administrators')->whereIn('party_id', $parties)->select('user_id'))
                ->when($superAdmins, fn (Builder $q) => $q->orWhereHas('roles', fn (Builder $role) => $role->where('name', Utils::getSuperAdminName()))))
            ->get();
    }

    /**
     * Only the administrators of the matters' assistants.
     *
     * @param  list<int>  $matterIds
     * @return Collection<int, User>
     */
    public static function administrators(array $matterIds): Collection
    {
        return User::query()
            ->whereIn('id', DB::table('assistant_administrators')->whereIn('party_id', self::parties($matterIds, true))->select('user_id'))
            ->get();
    }

    /** The matters' experts (or assistants), as a subquery of their parties. */
    private static function parties(array $matterIds, bool $assistantsOnly): \Illuminate\Database\Query\Builder
    {
        return DB::table('matter_party')
            ->whereIn('matter_id', $matterIds)
            ->where('role', 'expert')
            ->when($assistantsOnly, fn ($q) => $q->where('type', 'assistant'))
            ->select('party_id');
    }
}
