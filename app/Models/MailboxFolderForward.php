<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mailbox's folder forwarded on to an address of its own (Settings → Mailbox folders): each email that comes
 * into it after the last one gone on (last_uid), forwarded once (MailboxFolderForwarder).
 */
#[Fillable('mail_sender_id', 'folder', 'forward_to', 'last_uid', 'is_active', 'error')]
class MailboxFolderForward extends Model
{
    public function casts(): array
    {
        return [
            'last_uid' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function mailSender(): BelongsTo
    {
        return $this->belongsTo(MailSender::class);
    }
}
