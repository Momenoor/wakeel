<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report sent to one reviewer on WhatsApp (see ReportReviewWhatsApp): the
 * buttons they press come back with its id, so an answer is always matched
 * to the request it is about.
 */
#[Fillable('matter_request_id', 'user_id', 'phone', 'round', 'message_id', 'status', 'decision', 'awaiting', 'pending_texts', 'pending_files', 'received_message_ids', 'error', 'sent_at', 'answered_at')]
class ReportReviewDelivery extends Model
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const ANSWERED = 'answered';

    /** Decided by another reviewer. */
    public const CLOSED = 'closed';

    public function casts(): array
    {
        return [
            'pending_texts' => 'array',
            'pending_files' => 'array',
            'received_message_ids' => 'array',
            'sent_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MatterRequest::class, 'matter_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
