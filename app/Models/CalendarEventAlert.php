<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One alarm of a calendar event for one person: its reminder item in their Outlook calendar (made, then taken
 * away once it has rung) and its system notification. App\Services\MMS\Calendar\CalendarAlarms keeps them.
 */
#[Fillable('calendar_event_id', 'party_id', 'user_id', 'minutes_before', 'alarm_at', 'mailbox', 'outlook_item_id', 'notified_at', 'removed_at', 'error', 'attempts', 'retry_at', 'gave_up_at')]
class CalendarEventAlert extends Model
{
    /** A day before, and an hour before. */
    public const OFFSETS = [1440, 60];

    protected function casts(): array
    {
        return [
            'minutes_before' => 'integer',
            'alarm_at' => 'datetime',
            'notified_at' => 'datetime',
            'removed_at' => 'datetime',
            'attempts' => 'integer',
            'retry_at' => 'datetime',
            'gave_up_at' => 'datetime',
        ];
    }

    /** Whether its reminder item may be tried now: not given up on, and not waiting to be tried again. */
    public function mayTry(): bool
    {
        return $this->gave_up_at === null && ($this->retry_at === null || $this->retry_at->lte(now()));
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
