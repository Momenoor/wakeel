<?php

namespace App\Models;

use App\Enums\LetterStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A letter issued on a matter, from a template: its reference number
 * (JPA/{year}/{number}/{sequence}), the inputs it was filled with, and a
 * frozen copy of the letter as issued — editing the template afterwards
 * never changes a letter already sent.
 */
#[Fillable('letter_template_id', 'matter_id', 'reference', 'sequence', 'letterhead_id', 'sent_by', 'sender_key', 'subject', 'attention', 'locale', 'body', 'inputs', 'rendered_html', 'letter_date', 'status', 'sent_at')]
class MatterLetter extends Model
{
    public function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'letter_date' => 'date',
            'inputs' => 'array',
            'sequence' => 'integer',
            'status' => LetterStatus::class,
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

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MatterLetterRecipient::class);
    }

    /**
     * The next number in the matter's own letter sequence.
     */
    public static function nextSequenceFor(Matter $matter): int
    {
        return (int) static::query()->where('matter_id', $matter->getKey())->max('sequence') + 1;
    }

    /**
     * JPA/{matter year}/{matter number}/{sequence}.
     */
    /** The letter reference format until it is changed in System Settings. */
    public const DEFAULT_REFERENCE_FORMAT = 'JPA/{year}/{number}/{seq}';

    /** Copied in on a letter's email until System Settings says otherwise: the assistants. */
    public const DEFAULT_CC_EXPERT_TYPES = ['assistant', 'external-assistant'];

    /**
     * Which of the matter's experts are copied in on every letter's email
     * (Party::expertTypeOptions(): certified, assistant, external,
     * external-assistant) — System Settings; none ticked, no one.
     *
     * @return list<string>
     */
    public static function ccExpertTypes(): array
    {
        $types = Setting::get('letter_cc_expert_types');

        return is_array($types) ? array_values($types) : self::DEFAULT_CC_EXPERT_TYPES;
    }

    /**
     * The emails of the matter's experts copied in on its letters and
     * minutes — the kinds ticked in System Settings (the assistants, unless
     * changed) — from their party, or the account they sign in with.
     *
     * @return list<string>
     */
    public static function ccEmails(?Matter $matter): array
    {
        $types = self::ccExpertTypes();

        if (! $matter || $types === []) {
            return [];
        }

        return $matter->matterParties()
            ->with('party.user')
            ->where('role', 'expert')
            ->whereIn('type', $types)
            ->get()
            ->flatMap(fn (MatterParty $assistant): array => array_filter((array) ($assistant->party?->email ?: $assistant->party?->user?->email)))
            ->filter(fn ($email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The reference for a matter's nth letter, from the format in System
     * Settings: {year} and {number} the matter's, {seq} the letter's number
     * on the matter, {current_year} the year it is issued.
     */
    public static function referenceFor(Matter $matter, int $sequence): string
    {
        $format = (string) (Setting::get('letter_reference_format') ?: self::DEFAULT_REFERENCE_FORMAT);

        // A format without {seq} would give every letter on a matter the
        // same reference.
        if (! str_contains($format, '{seq}')) {
            $format = self::DEFAULT_REFERENCE_FORMAT;
        }

        return strtr($format, [
            '{year}' => (string) $matter->year,
            '{number}' => (string) $matter->number,
            '{seq}' => (string) $sequence,
            '{current_year}' => now()->format('Y'),
        ]);
    }
}
