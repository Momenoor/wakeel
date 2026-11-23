<?php

namespace App\Mail;

use App\Mail\Concerns\AttachesRequestFiles;
use App\Models\Matter;
use App\Models\MatterRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RequestActionNotificationMail extends Mailable
{
    use AttachesRequestFiles, Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Matter $matter,
        public MatterRequest $matterRequest,
        public string $statusLabel
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Request :status', ['status' => $this->statusLabel])
            .' — '.$this->matter->year.'/'.$this->matter->number.($this->matter->type ? ' — '.$this->matter->type->name : ''),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.request-action-notification',
        );
    }

    /**
     * The decision's files — what the reviewer sent back — go with the email; the request's own, which the
     * assistant has, are linked in it (mails.partials.request-attachments).
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->requestFiles($this->matterRequest->decisionAttachments());
    }
}
