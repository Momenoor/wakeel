<?php

namespace App\Observers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\Notifier;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/**
 * A new leave request is announced by email the moment it is filed — the
 * office reviews and decides from the inbox, via the one-click approve/reject
 * links the mail carries, rather than needing to be in the panel at all.
 * The same people are told in the system too (bell, desktop, phone): those
 * set in System Settings, else everyone who can approve leave.
 */
class LeaveRequestObserver
{
    public function created(LeaveRequest $leaveRequest): void
    {
        $recipients = self::recipients();

        if ($recipients === []) {
            return;
        }

        $type = NotificationType::LEAVE_SUBMITTED;
        $users = User::whereIn('email', $recipients)->get();

        // The mail, one to each address: to those who are users, as they chose; an address that is
        // no user's (typed in System Settings) always.
        foreach ($recipients as $address) {
            $owners = $users->filter(fn (User $user): bool => strcasecmp((string) $user->email, $address) === 0);

            if ($owners->isNotEmpty() && NotificationPreferences::wanting($type, $owners, NotificationChannel::MAIL)->isEmpty()) {
                continue;
            }

            Mail::to($address)
                ->locale('ar')
                ->queue(new LeaveRequestSubmittedMail($leaveRequest));
        }

        // The bell, and WhatsApp for those who chose it, to the users among them.
        Notifier::send(
            $type,
            $users,
            __('New leave request'),
            __(':name requested leave from :start to :end.', [
                'name' => $leaveRequest->party?->name ?? '—',
                'start' => $leaveRequest->start_date?->format('d/m/Y') ?? '—',
                'end' => $leaveRequest->end_date?->format('d/m/Y') ?? '—',
            ]),
            Route::has('filament.mms.resources.leave-requests.edit')
                ? route('filament.mms.resources.leave-requests.edit', $leaveRequest)
                : null,
            only: [NotificationChannel::BELL, NotificationChannel::WHATSAPP],
        );
    }

    /**
     * The addresses set in System Settings (Notifications) — or, none set,
     * everyone who can approve leave requests.
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        $set = array_values(array_filter((array) Setting::get('leave_request_recipients', []), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));

        if ($set !== []) {
            return $set;
        }

        return User::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $role) => $role->where('name', Utils::getSuperAdminName())
                    ->orWhereHas('permissions', fn (Builder $permission) => $permission->where('name', 'Approve:LeaveRequest')))
                ->orWhereHas('permissions', fn (Builder $permission) => $permission->where('name', 'Approve:LeaveRequest')))
            ->whereNotNull('email')
            ->orderBy('id')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();
    }
}
