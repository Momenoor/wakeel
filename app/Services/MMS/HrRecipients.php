<?php

namespace App\Services\MMS;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who hears of an employee's request — leave, a loan, petty cash, a certificate — and decides it: the addresses
 * set in Settings → Letters & emails (Leave requests), else everyone who may approve it (super admins and the
 * holders of its approve permission).
 */
class HrRecipients
{
    /**
     * @return list<string>
     */
    public static function recipients(string $permission): array
    {
        $set = array_values(array_filter((array) Setting::get('leave_request_recipients', []), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));

        if ($set !== []) {
            return $set;
        }

        return User::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $role) => $role->where('name', Utils::getSuperAdminName())
                    ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $permission)))
                ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $permission)))
            ->whereNotNull('email')
            ->orderBy('id')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Of recipients(), those its emails go to: those who are users, as they chose (the notification $type); an
     * address that is no user's (typed in the settings) always.
     *
     * @return list<string>
     */
    public static function mailed(string $permission, NotificationType $type): array
    {
        $recipients = self::recipients($permission);
        $users = User::whereIn('email', $recipients)->get();
        // Their roles and choices read for them all at once, not as each address is asked about.
        NotificationPreferences::make()->readFor($users);

        return array_values(array_filter($recipients, function (string $address) use ($users, $type): bool {
            $owners = $users->filter(fn (User $user): bool => strcasecmp((string) $user->email, $address) === 0);

            return $owners->isEmpty() || NotificationPreferences::wanting($type, $owners, NotificationChannel::MAIL)->isNotEmpty();
        }));
    }
}
