<?php

namespace App\Filament\Support;

use Tiptap\Core\Extension;

/**
 * The shading of a table cell, so the server keeps it when it reads the
 * editor's content.
 */
class CellBackgroundExtension extends Extension
{
    /**
     * @var string
     */
    public static $name = 'cellBackground';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function addGlobalAttributes(): array
    {
        return [[
            'types' => ['tableCell', 'tableHeader'],
            'attributes' => [
                'data-bg' => [
                    'default' => null,
                    'parseHTML' => fn ($DOMNode) => self::colour($DOMNode->getAttribute('data-bg')),
                    'renderHTML' => function ($attributes): array {
                        $colour = self::colour(is_array($attributes) ? ($attributes['data-bg'] ?? null) : ($attributes->{'data-bg'} ?? null));

                        return $colour ? ['data-bg' => $colour, 'style' => 'background-color: '.$colour] : [];
                    },
                ],
            ],
        ]];
    }

    /** Only a #rrggbb colour reaches the style attribute. */
    private static function colour(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : null;
    }
}
