<?php

namespace App\Mail;

use App\Models\EmployeeRequest;
use App\Services\MMS\EmployeeRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * To the office the moment an employee request is filed, with signed approve/reject links that need no login
 * (as a leave request's): approving there takes it as asked — a change needs its page.
 */
class EmployeeRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EmployeeRequest $employeeRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('New :type request', ['type' => $this->employeeRequest->type->getLabel()]).' — '.$this->employeeRequest->party?->name);
    }

    public function content(): Content
    {
        $link = fn (string $action): string => URL::signedRoute('employee-request.email-action.'.$action, ['employeeRequest' => $this->employeeRequest->getKey()], now()->addDays(7));

        return new Content(view: 'mails.employee-request', with: [
            'heading' => __('New :type request', ['type' => $this->employeeRequest->type->getLabel()]),
            'intro' => __('A new request has been submitted and requires your review.'),
            'rows' => EmployeeRequestService::rows($this->employeeRequest),
            'approveUrl' => $link('approve'),
            'rejectUrl' => $link('reject'),
            'pageUrl' => EmployeeRequestService::url($this->employeeRequest),
        ]);
    }
}
