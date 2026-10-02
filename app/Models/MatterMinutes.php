<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meeting's minutes (محضر) on a matter, numbered per matter: prepared
 * with the questions to ask, filled in at the meeting — who attended, the
 * answers, the template's own fields (deadlines…) — and finalised: its
 * wording kept, its PDF among the matter's attachments.
 *
 * attendees: [{present, title, name, capacity, id_number, phone, party_id}]
 * items: [{type: question|comment, text, answer}]
 */
#[Fillable('matter_id', 'letter_template_id', 'letterhead_id', 'calendar_event_id', 'number', 'meeting_at', 'meeting_link', 'attendees', 'items', 'inputs', 'body', 'status', 'attachment_id', 'created_by', 'finalized_at')]
class MatterMinutes extends Model
{
    public const DRAFT = 'draft';

    public const FINAL = 'final';

    protected $table = 'matter_minutes';

    public function casts(): array
    {
        return [
            'meeting_at' => 'datetime',
            'finalized_at' => 'datetime',
            'attendees' => 'array',
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
