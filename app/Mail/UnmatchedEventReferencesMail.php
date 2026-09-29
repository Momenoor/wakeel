<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily list, for super admins and admins, of calendar events naming a
 * matter number that is not in the system.
 */
class UnmatchedEventReferencesMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{date: string, title: string, missing: list<string>}>  $rows
     */
    public function __construct(public array $rows, public string $dashboardUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans_choice('{1} 1 calendar event names a matter that is not in the system|[2,*] :count calendar events name a matter that is not in the system', count($this->rows), ['count' => count($this->rows)]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mails.unmatched-event-references');
    }
}
