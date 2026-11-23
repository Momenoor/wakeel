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

class NewRequestNotificationMail extends Mailable
{
    use AttachesRequestFiles, Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public Matter $matter, public MatterRequest $matterRequest)
    {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('New Request Submitted')
            .' — '.$this->matter->year.'/'.$this->matter->number.($this->matter->type ? ' — '.$this->matter->type->name : ''),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.new-request-notification',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->requestFiles($this->matterRequest->attachments()->get());
    }
}
