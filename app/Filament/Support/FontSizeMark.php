<?php

namespace App\Filament\Support;

use Tiptap\Core\Mark;
use Tiptap\Utils\HTML;

/**
 * The font size mark, so the server keeps it when it reads the editor's
 * content.
 */
class FontSizeMark extends Mark
{
    /**
     * @var string
     */
    public static $name = 'fontSize';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseHTML(): array
    {
        return [['tag' => 'span[data-font-size]']];
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function addAttributes(): array
    {
        return [
            'data-font-size' => [
                'parseHTML' => fn ($DOMNode) => self::size($DOMNode->getAttribute('data-font-size')),
                'renderHTML' => function ($attributes): array {
                    $size = self::size(is_array($attributes) ? ($attributes['data-font-size'] ?? null) : ($attributes->{'data-font-size'} ?? null));

                    return $size ? ['data-font-size' => $size, 'style' => 'font-size: '.$size] : [];
                },
            ],
        ];
    }

    /**
     * @param  mixed  $mark
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array<mixed>
     */
    public function renderHTML($mark, $HTMLAttributes = []): array
    {
        return ['span', HTML::mergeAttributes($HTMLAttributes), 0];
    }

    /**
     * Only a size in points — nothing else reaches the style attribute.
     */
    private static function size(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{1,2}(\.\d)?pt$/', $value) ? $value : null;
    }
}
