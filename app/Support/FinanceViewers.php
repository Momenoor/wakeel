<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Who may see salaries and incentives for the time being (Settings → General): with users chosen there, the
 * incentive screens, Financial configuration, payroll runs, the end-of-service voucher, employees' loans and
 * salary components are theirs alone, whatever anyone's permissions say; with none chosen, the permissions decide.
 */
final class FinanceViewers
{
    /** Setting: the ids of the users; empty for the permissions to decide. */
    public const SETTING = 'finance_viewers';

    /**
     * @return list<int>
     */
    public static function ids(): array
    {
        return array_values(array_filter(array_map('intval', (array) Setting::get(self::SETTING, []))));
    }

    /** Whether someone may see them: everyone, until users are chosen; then those alone. */
    public static function allows(?User $user): bool
    {
        $ids = self::ids();

        return $ids === [] || ($user !== null && in_array((int) $user->getKey(), $ids, true));
    }
}
