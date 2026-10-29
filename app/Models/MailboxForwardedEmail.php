<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An email of a mailbox's folder forwarded on (MailboxFolderForwarder): to whom, when — or, not gone yet, why.
 */
#[Fillable('mail_sender_id', 'folder', 'message_id', 'subject', 'from', 'at', 'forwarded_to', 'forwarded_at', 'attempts', 'error')]
class MailboxForwardedEmail extends Model
{
    public function casts(): array
    {
        return [
            'at' => 'datetime',
            'forwarded_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
