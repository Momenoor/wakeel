<?php

namespace App\Models;

use App\Events\MinutesLiveUpdated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A meeting's minutes (محضر) on a matter, numbered per matter: prepared
 * with the questions to ask, filled in at the meeting — who attended, the
 * answers, the template's own fields (deadlines…) — and finalised: its
 * wording kept, its PDF among the matter's attachments.
 *
 * attendees: [{present, title, name, capacity, id_number, phone, party_id}]
 * items: [{type: question|comment, text, answer}]
 * attendance: who joined its Teams call, and when (TeamsAttendance): [{name, email, role, joined_at, left_at, seconds, intervals, party_id}]
 */
#[Fillable('matter_id', 'letter_template_id', 'letterhead_id', 'calendar_event_id', 'number', 'meeting_at', 'meeting_link', 'attendees', 'attendance', 'attendance_read_at', 'items', 'inputs', 'body', 'status', 'attachment_id', 'created_by', 'finalized_at', 'opening', 'closing', 'ended_at')]
class MatterMinutes extends Model
{
    use LogsActivity;

    /** Its number, meeting and status: on the matter's history (created, finalised) — not what autosave writes as it is typed. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['matter_id', 'number', 'meeting_at', 'status'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        // The live view the attendees watch refreshes on it.
        // Only a save that changed something (updated does not fire for none).
        static::created(fn (self $minutes) => MinutesLiveUpdated::announce($minutes->getKey()));
        static::updated(fn (self $minutes) => MinutesLiveUpdated::announce($minutes->getKey()));
    }

    public const DRAFT = 'draft';

    public const FINAL = 'final';

    protected $table = 'matter_minutes';

    public function casts(): array
    {
        return [
            'meeting_at' => 'datetime',
            'ended_at' => 'datetime',
            'finalized_at' => 'datetime',
            'attendees' => 'array',
            'attendance' => 'array',
            'attendance_read_at' => 'datetime',
            'items' => 'array',
            'inputs' => 'array',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'letter_template_id');
    }

    public function letterhead(): BelongsTo
    {
        return $this->belongsTo(Letterhead::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /** Sent to the attendees to sign, and what came back. */
    /** Sent to anyone — by email or WhatsApp — at least once. */
    /** Where the meeting is joined: the link kept on the minutes, else its calendar meeting's (Teams). */
    public function joinUrl(): ?string
    {
        $url = $this->meeting_link ?: $this->event?->online_meeting_url;

        return filled($url) && preg_match('~^https?://~i', (string) $url) === 1 ? (string) $url : null;
    }

    public function wasSent(): bool
    {
        return $this->deliveries()->whereNotNull('sent_at')->exists();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MinutesDelivery::class, 'matter_minutes_id');
    }

    public function isFinal(): bool
    {
        return $this->status === self::FINAL;
    }

    public static function nextNumber(Matter $matter): int
    {
        return (int) static::query()->where('matter_id', $matter->getKey())->max('number') + 1;
    }

    /**
     * The questions answered, of those asked.
     *
     * @return array{answered: int, total: int}
     */
    public function progress(): array
    {
        $questions = collect($this->items ?? [])->where('type', 'question');

        return ['answered' => $questions->filter(fn (array $item) => filled($item['answer'] ?? null))->count(), 'total' => $questions->count()];
    }
}
