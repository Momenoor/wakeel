<?php

namespace App\Mail;

use App\Enums\LeaveType;
use App\Models\LeaveRequest;
use App\Services\MMS\LeaveBalanceService;
use App\Services\MMS\LeaveRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the office the moment a leave request is filed, carrying signed
 * approve/reject links that need no login — the signature on each URL is the
 * only authentication, exactly like the existing matter received-date flow.
 */
class LeaveRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $approveUrl;

    public string $rejectUrl;

    /**
     * The annual balance as it stood before this request — an approver reading
     * the email needs to see what is left, not just what was asked for.
     */
    public float $annualLeaveBalance;

    /**
     * Of the days asked for, those approving it as it stands takes off that balance (annual leave while
     * it lasts) — shown with the balance after.
     */
    public float $fromBalance;

    public function __construct(public LeaveRequest $leaveRequest)
    {
        $this->approveUrl = URL::signedRoute(
            'leave-request.email-action.approve',
            ['leaveRequest' => $leaveRequest->id],
            now()->addDays(7),
        );

        $this->rejectUrl = URL::signedRoute(
            'leave-request.email-action.reject',
            ['leaveRequest' => $leaveRequest->id],
            now()->addDays(7),
        );

        $this->annualLeaveBalance = app(LeaveBalanceService::class)->balance((int) $leaveRequest->party_id);
        $this->fromBalance = (float) collect(app(LeaveRequestService::class)->suggestSplit($leaveRequest))
            ->where('leave_type', LeaveType::ANNUAL->value)
            ->sum('day_count');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('New Leave Request').' — '.$this->leaveRequest->party->name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mails.leave-request-submitted');
    }
}
