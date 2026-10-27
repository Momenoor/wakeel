<?php

namespace App\Mail;

use App\Enums\LeaveType;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestPeriod;
use App\Services\MMS\LeaveBalanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the requester once their leave request has been approved or
 * rejected, whether that decision came from the panel or from an office
 * email's one-click link.
 */
class LeaveRequestDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    /** The annual days the approval took off the vacation balance — none for any other leave. */
    public float $fromBalance = 0;

    /** The balance before them. */
    public float $balanceBefore = 0;

    /**
     * @param  string|null  $change  what was changed on an approved leave (its types, its end, its cancellation) —
     *                               null for the decision itself
     */
    public function __construct(public LeaveRequest $leaveRequest, public ?string $change = null)
    {
        if ($leaveRequest->isApproved()) {
            $this->fromBalance = (float) $leaveRequest->periods()->get()
                ->filter(fn (LeaveRequestPeriod $period): bool => LeaveType::fromBalance($period->leave_type))
                ->sum('day_count');
            // Taken off already: what it was, before them.
            $this->balanceBefore = app(LeaveBalanceService::class)->balance((int) $leaveRequest->party_id) + $this->fromBalance;
        }
    }

    public function envelope(): Envelope
    {
        $statusLabel = $this->leaveRequest->status->getLabel();

        return new Envelope(
            subject: __('Leave Request').' — '.$statusLabel,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mails.leave-request-decision');
    }
}
