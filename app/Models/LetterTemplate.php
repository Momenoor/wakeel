<?php

namespace App\Models;

use App\Enums\LetterTemplateCategories;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A letter's wording, with {{placeholders}}, and the form to fill when
 * issuing it (`inputs`): [{key, label, type, required, options, group,
 * heading}] — type is text | textarea | date | time | url | number |
 * select | items (a numbered list ticked from the item library's group).
 */
#[Fillable('name', 'slug', 'subject', 'body', 'placeholders', 'locale', 'is_active', 'is_default', 'category', 'letterhead_id', 'inputs', 'minutes_opening', 'minutes_closing', 'email_template_id', 'minutes_attendees', 'recipients_layout', 'recipients_format', 'letter_font_id', 'arabic_font_id')]
class LetterTemplate extends Model
{
    public const INPUT_TYPES = ['text', 'textarea', 'date', 'time', 'url', 'number', 'select', 'items'];

    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'placeholders' => 'array',
            'inputs' => 'array',
            'minutes_attendees' => 'array',
            'recipients_format' => 'array',
            'category' => LetterTemplateCategories::class,
        ];
    }

    public function matterLetters(): HasMany
    {
        return $this->hasMany(MatterLetter::class);
    }

    public function letterhead(): BelongsTo
    {
        return $this->belongsTo(Letterhead::class);
    }

    /**
     * The matter types this template is for — none means every type.
     */
    /** The font its English text is written in; none: the standard one. */
    public function font(): BelongsTo
    {
        return $this->belongsTo(LetterFont::class, 'letter_font_id');
    }

    /** The font its Arabic text is written in; none: as the English font, or the standard one. */
    public function arabicFont(): BelongsTo
    {
        return $this->belongsTo(LetterFont::class, 'arabic_font_id');
    }

    /** The covering email it is sent with, unless another is chosen. */
    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function types(): BelongsToMany
    {
        // Set from the matter type; `excluded`: the template is NOT offered for that type.
        return $this->belongsToMany(Type::class, 'letter_template_type')->withPivot('excluded');
    }

    /** The courts it is for; none: every court. */
    public function courts(): BelongsToMany
    {
        return $this->belongsToMany(Court::class, 'letter_template_court');
    }

    /**
     * The external entities this template is written to.
     */
    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'letter_template_party');
    }

    /**
     * The template to start a letter to this entity from: an active one,
     * for the matter's type and court, written to it.
     */
    public static function forEntity(int $partyId, ?int $typeId, ?int $courtId = null): ?self
    {
        return static::query()
            ->forMatterType($typeId)
            ->forCourt($courtId)
            ->letters()
            ->whereHas('entities', fn (Builder $q) => $q->whereKey($partyId))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    /**
     * Active templates usable for a matter of this type: those linked to
     * it, those for every type except others, and those linked to no type
     * at all.
     *
     * @param  Builder<LetterTemplate>  $query
     */
    public function scopeForMatterType(Builder $query, ?int $typeId): void
    {
        $query->where('is_active', true)->offeredForType($typeId);
    }

    /**
     * Those offered for this type. Linked to no type: every type. Linked to
     * types: those only (the type is among them). Linked only as excluded
     * from types ("all except"): every type but those. A matter with no type
     * gets those linked to no type, and the "all except" ones.
     *
     * @param  Builder<LetterTemplate>  $query
     */
    public function scopeOfferedForType(Builder $query, ?int $typeId): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('types')
            ->when($typeId, fn (Builder $q) => $q->orWhereHas('types', fn (Builder $t) => $t->whereKey($typeId)->where('letter_template_type.excluded', false)))
            ->orWhere(fn (Builder $q) => $q
                // Only exclusions: every type but the excluded.
                ->whereDoesntHave('types', fn (Builder $t) => $t->where('letter_template_type.excluded', false))
                ->whereHas('types')
                ->when($typeId, fn (Builder $q) => $q->whereDoesntHave('types', fn (Builder $t) => $t->whereKey($typeId)))));
    }

    /**
     * Those usable at this court: linked to it, or to no court at all.
     *
     * @param  Builder<LetterTemplate>  $query
     */
    public function scopeForCourt(Builder $query, ?int $courtId): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('courts')
            ->when($courtId, fn (Builder $q) => $q->orWhereHas('courts', fn (Builder $c) => $c->whereKey($courtId))));
    }

    /**
     * Active templates for this matter: its type's and its court's (or for
     * every type, every court).
     *
     * @param  Builder<LetterTemplate>  $query
     */
    public function scopeForMatter(Builder $query, Matter $matter): void
    {
        $query->forMatterType($matter->type_id)->forCourt($matter->court_id);
    }

    /**
     * Those issued as a matter's letter: not minutes, not employee certificates.
     *
     * @param  Builder<LetterTemplate>  $query
     */
    public function scopeLetters(Builder $query): void
    {
        $query->whereNotIn('category', LetterTemplateCategories::notLetters());
    }

    /**
     * The active employee certificates (Employee requests).
     *
     * @param  Builder<LetterTemplate>  $query
     */
    public function scopeCertificates(Builder $query): void
    {
        $query->where('is_active', true)->where('category', LetterTemplateCategories::CERTIFICATES->value);
    }

    /**
     * Minutes templates for this matter: active, for its type and court.
     *
     * @param  Builder<LetterTemplate>  $query
     */
    public function scopeMinutesFor(Builder $query, Matter $matter): void
    {
        $query->forMatter($matter)->where('category', LetterTemplateCategories::MINUTES->value);
    }
}
