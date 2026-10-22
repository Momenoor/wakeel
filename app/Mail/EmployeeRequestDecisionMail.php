<?php

namespace App\Mail;

use App\Enums\RequestStatus;
use App\Models\EmployeeRequest;
use App\Services\MMS\EmployeeRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The decision on an employee request, to the employee (the office copied in): what was approved — as the
 * approver left it — or why it was rejected; an issued certificate goes with it.
 */
class EmployeeRequestDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EmployeeRequest $employeeRequest) {}

    private function approved(): bool
    {
        return $this->employeeRequest->status === RequestStatus::APPROVED;
    }

    public function envelope(): Envelope
    {
        $type = $this->employeeRequest->type->getLabel();

        return new Envelope(subject: ($this->approved() ? __(':type request approved', ['type' => $type]) : __(':type request rejected', ['type' => $type])).' — '.$this->employeeRequest->party?->name);
    }

    public function content(): Content
    {
        $request = $this->employeeRequest;
        $type = $request->type->getLabel();

        return new Content(view: 'mails.employee-request', with: [
            'heading' => $this->approved() ? __(':type request approved', ['type' => $type]) : __(':type request rejected', ['type' => $type]),
            'intro' => match (true) {
                ! $this->approved() => __('Reason: :reason', ['reason' => (string) $request->approved_comment]),
                $request->type->isMoney() => __('It is paid to you soon; you are told when it is.'),
                default => __('Your certificate is attached.'),
            },
            'rows' => [
                ...EmployeeRequestService::rows($request),
                ...($this->approved() && filled($request->approved_comment) ? [__('Note') => (string) $request->approved_comment] : []),
            ],
            'pageUrl' => EmployeeRequestService::url($request),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $certificate = $this->approved() ? $this->employeeRequest->certificate() : null;

        return $certificate && $certificate->fileExists()
            ? [Attachment::fromStorageDisk($certificate->disk ?: 'public', $certificate->path)->as((string) $certificate->name)->withMime('application/pdf')]
            : [];
    }
}
