<?php

namespace App\Filament\Mms\Resources\Types\Pages\Concerns;

use App\Models\Type;

/**
 * The letter templates of a matter type — those offered for it, and those left
 * out for it ("all types except these") — are not columns of the type: they are
 * read into the form when it opens and saved from it after the type is.
 */
trait SavesLetterTemplates
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withLetterTemplates(array $data, Type $type): array
    {
        return [
            ...$data,
            'letter_templates_offered' => $type->offeredLetterTemplates()->pluck('letter_templates.id')->map(fn ($id) => (int) $id)->all(),
            'letter_templates_excluded' => $type->excludedLetterTemplates()->pluck('letter_templates.id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    protected function saveLetterTemplates(Type $type): void
    {
        $type->assignLetterTemplates(
            (array) ($this->data['letter_templates_offered'] ?? []),
            (array) ($this->data['letter_templates_excluded'] ?? []),
        );
    }
}
