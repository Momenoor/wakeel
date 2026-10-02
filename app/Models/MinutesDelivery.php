<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minutes sent to one attendee to sign — by email or WhatsApp — and, once
 * they send it back signed, the copies received (filed with the matter and
 * in its OneDrive folder).
 */
#[Fillable('matter_minutes_id', 'party_id', 'name', 'channel', 'address', 'message_id', 'status', 'error', 'sent_at', 'signed_at', 'signed_attachments', 'received_message_ids', 'onedrive_url', 'onedrive_error', 'sent_by')]
class MinutesDelivery extends Model
{
    public const EMAIL = 'email';

    public const WHATSAPP = 'whatsapp';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SIGNED = 'signed';

    public function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'signed_attachments' => 'array',
            'received_message_ids' => 'array',
        ];
    }

    public function minutes(): BelongsTo
    {
        return $this->belongsTo(MatterMinutes::class, 'matter_minutes_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
