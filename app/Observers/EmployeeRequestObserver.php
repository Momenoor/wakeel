<?php

namespace App\Observers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Mail\EmployeeRequestSubmittedMail;
use App\Models\EmployeeRequest;
use App\Models\User;
use App\Services\MMS\EmployeeRequestService;
use App\Services\MMS\HrRecipients;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\Mail;

/**
 * A new employee request is announced as a leave request is (LeaveRequestObserver): one email to the office,
 * with its approve and reject links, and the bell (WhatsApp, as each chose) to those of them who are users.
 */
class EmployeeRequestObserver
{
    public function created(EmployeeRequest $request): void
    {
        $recipients = HrRecipients::recipients(EmployeeRequestService::APPROVE);

        if ($recipients === []) {
            return;
        }

        if (($addresses = HrRecipients::mailed(EmployeeRequestService::APPROVE, NotificationType::EMPLOYEE_REQUEST_SUBMITTED)) !== []) {
            Mail::to($addresses)->locale('ar')->queue(new EmployeeRequestSubmittedMail($request));
        }

        $request->loadMissing('party');
        $users = User::whereIn('email', $recipients)->get();

        Notifier::send(
            NotificationType::EMPLOYEE_REQUEST_SUBMITTED,
            $users,
            __('New :type request', ['type' => $request->type->getLabel()]),
            __(':name asked for :type.', ['name' => (string) $request->party?->name, 'type' => $request->type->getLabel()]),
            EmployeeRequestService::url($request, $users->first()),
            only: [NotificationChannel::BELL, NotificationChannel::WHATSAPP],
        );
    }
}
