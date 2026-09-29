<?php

namespace App\Observers;

use App\Mail\LeaveRequestSubmittedMail;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Notify\UserAlert;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/**
 * A new leave request is announced by email the moment it is filed — the
 * office reviews and decides from the inbox, via the one-click approve/reject
 * links the mail carries, rather than needing to be in the panel at all.
 * The same people are told in the system too (bell, desktop, phone).
 */
class LeaveRequestObserver
{
    private const TO = 'redha@jpaemirates.com';

    private const CC = [
        'expert@jpaemirates.com',
        'momen.noor@jpaemirates.com',
        'info@jpaemirates.com',
    ];

    public function created(LeaveRequest $leaveRequest): void
    {
        Mail::to(self::TO)
            ->cc(self::CC)
            ->locale('ar')
            ->queue(new LeaveRequestSubmittedMail($leaveRequest));

        UserAlert::send(
            User::whereIn('email', [self::TO, ...self::CC])->get(),
            __('New leave request'),
            __(':name requested leave from :start to :end.', [
                'name' => $leaveRequest->party?->name ?? '—',
                'start' => $leaveRequest->start_date?->format('d/m/Y') ?? '—',
                'end' => $leaveRequest->end_date?->format('d/m/Y') ?? '—',
            ]),
            Route::has('filament.mms.resources.leave-requests.edit')
                ? route('filament.mms.resources.leave-requests.edit', $leaveRequest)
                : null,
        );
    }
}
