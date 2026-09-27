<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

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
     */
    public function __construct(
        public string $emailSubject,
        public string $letterHtml,
        public bool $rtl,
        public array $images = [],
        public array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
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
