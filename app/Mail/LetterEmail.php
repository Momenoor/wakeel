<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Email;

/**
 * A letter sent by email: either a covering email with the letter attached
 * (PDF and/or Word), or the letter itself as the email body. Images the
 * letter refers to by file path (signature, stamp) are embedded in the
 * email — a mail program can't load files from the server's disk.
 */
class LetterEmail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, string>  $images  placeholder token => file path
     * @param  list<array{name: string, data: string, mime: string}>  $files
     * @param  list<array{name: string, size: int}>  $sharedFiles  files too large to attach, sent as a link in the email (SharedAttachments)
     * @param  ?string  $inReplyTo  the id of the email this one answers: the reply threads under it in the recipient's mailbox
     */
    public function __construct(
        public string $emailSubject,
        public string $letterHtml,
        public bool $rtl,
        public array $images = [],
        public array $files = [],
        public array $sharedFiles = [],
        public ?string $inReplyTo = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject, using: [function (Email $message): void {
            if (filled($this->inReplyTo)) {
                $message->getHeaders()->addIdHeader('In-Reply-To', trim((string) $this->inReplyTo, '<>'));
            }
        }]);
    }

    public function headers(): Headers
    {
        return new Headers(references: filled($this->inReplyTo) ? [trim((string) $this->inReplyTo, '<>')] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mails.letter');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromData(fn () => $file['data'], $file['name'])->withMime($file['mime']),
            $this->files,
        );
    }
}
