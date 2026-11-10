<?php

namespace App\Models;

use App\Enums\LetterSendChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A letter asked to be sent by whoever issued it (an assistant) of their administrator: the ways asked for
 * (LetterSendChannel), which of them are done and by whom, the SMS to copy and a note. One a letter; open until
 * every way asked for is done (LetterSendRequests).
 */
#[Fillable('matter_letter_id', 'matter_id', 'requested_by', 'channels', 'done', 'sms_text', 'note', 'completed_at')]
class LetterSendRequest extends Model
{
    public function casts(): array
    {
        return [
            'channels' => 'array',
            'done' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(MatterLetter::class, 'matter_letter_id');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null;
    }

    public function asks(LetterSendChannel $channel): bool
    {
        return in_array($channel->value, (array) $this->channels, true);
    }

    /** @return list<string> the ways asked for and done */
    public function doneChannels(): array
    {
        return LetterSendChannel::clean(array_intersect((array) $this->channels, array_keys((array) $this->done)));
    }

    /** @return list<string> the ways asked for and not done yet */
    public function pendingChannels(): array
    {
        return LetterSendChannel::clean(array_diff((array) $this->channels, array_keys((array) $this->done)));
    }
}
