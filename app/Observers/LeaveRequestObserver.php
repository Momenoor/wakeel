<?php

namespace App\Observers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\MMS\HrRecipients;
use App\Services\Notifications\Notifier;
use App\Support\Dates;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/**
 * A new leave request is announced by one email to them all the moment it is filed — the
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

        // The mail, one to them all — each sees who else has it.
        $addresses = self::mailed();

        if ($addresses !== []) {
            Mail::to($addresses)
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
                'start' => $leaveRequest->start_date?->format(Dates::dateFormat()) ?? '—',
                'end' => $leaveRequest->end_date?->format(Dates::dateFormat()) ?? '—',
            ]),
            Route::has('filament.mms.resources.leave-requests.edit')
                ? route('filament.mms.resources.leave-requests.edit', $leaveRequest)
                : null,
            only: [NotificationChannel::BELL, NotificationChannel::WHATSAPP],
        );
    }

    /**
     * Of recipients(), those the office's leave emails go to — the new request, and the decision copied
     * to them (HrRecipients::mailed()).
     *
     * @return list<string>
     */
    public static function mailed(): array
    {
        return HrRecipients::mailed('Approve:LeaveRequest', NotificationType::LEAVE_SUBMITTED);
    }

    /**
     * The addresses set in System Settings (Notifications) — or, none set,
     * everyone who can approve leave requests (HrRecipients).
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        return HrRecipients::recipients('Approve:LeaveRequest');
    }
}
