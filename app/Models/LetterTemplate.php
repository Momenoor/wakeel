<?php

namespace App\Models;

use App\Enums\LetterTemplateCategories;
use App\Filament\Mms\Forms\Components\RichEditor\RichContentCustomBlocks\HeroBlock;
use Filament\Forms\Components\RichEditor\MentionProvider;
use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A letter's wording, with {{placeholders}}, and the form to fill when
 * issuing it (`inputs`): [{key, label, type, required, options, group,
 * heading}] — type is text | textarea | date | time | url | number |
 * select | items (a numbered list ticked from the item library's group).
 */
#[Fillable('name', 'slug', 'subject', 'body', 'placeholders', 'locale', 'is_active', 'is_default', 'category', 'letterhead_id', 'inputs')]
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
