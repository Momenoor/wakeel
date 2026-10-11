<?php

namespace App\Models;

use App\Enums\LetterTemplateCategories;
use App\Filament\Mms\Forms\Components\RichEditor\RichContentCustomBlocks\HeroBlock;
use Filament\Forms\Components\RichEditor\MentionProvider;
use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
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
#[Fillable('name', 'slug', 'subject', 'body', 'placeholders', 'locale', 'is_active', 'is_default', 'category', 'letterhead_id', 'inputs', 'minutes_opening', 'minutes_closing', 'email_template_id', 'minutes_attendees', 'letter_font_id', 'arabic_font_id')]
class LetterTemplate extends Model implements HasRichContent
{
    use InteractsWithRichContent;

    public const INPUT_TYPES = ['text', 'textarea', 'date', 'time', 'url', 'number', 'select', 'items'];

    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'placeholders' => 'array',
            'inputs' => 'array',
            'minutes_attendees' => 'array',
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
        return $this->belongsToMany(Type::class, 'letter_template_type');
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
     * for the matter's type, written to it.
     */
    public static function forEntity(int $partyId, ?int $typeId): ?self
    {
        return static::query()
            ->forMatterType($typeId)
            ->where('category', '!=', LetterTemplateCategories::MINUTES->value)
            ->whereHas('entities', fn (Builder $q) => $q->whereKey($partyId))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    /**
     * Active templates usable for a matter of this type: those linked to
     * it, and those linked to no type at all.
     *
     * @param  Builder<LetterTemplate>  $query
     */
    public function scopeForMatterType(Builder $query, ?int $typeId): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('types')
                ->when($typeId, fn (Builder $q) => $q->orWhereHas('types', fn (Builder $t) => $t->whereKey($typeId))));
    }

    public function getFilamentRichContentField(): string
    {
        return 'body';
    }

    public function setUpRichContent()
    {
        $this->registerRichContent($this->getFilamentRichContentField())
            ->mentions([
                MentionProvider::make('@')
                    ->getSearchResultsUsing(fn ($query) => User::where('name', 'like', "%{$query}%")->pluck('name', 'id'))
                    ->getLabelsUsing(fn ($ids) => User::whereIn('id', $ids)->pluck('display_name', 'id'))
                    ->url(fn ($record) => route('filament.mms.resources.users.view', $record)),
            ])
            ->customBlocks([
                HeroBlock::class,
            ])
            ->toHtml();
    }
}
