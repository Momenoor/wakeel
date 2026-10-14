<?php

namespace App\Filament\Support;

use Tiptap\Core\Extension;

/**
 * The border and alignment of a table cell, so the server keeps them when it
 * reads the editor's content (and writes them into the HTML the PDF, Word and
 * email read):
 *
 *   data-bw       border width in px (0 = none)   → border-width / border-style
 *   data-bc       border colour, #rrggbb          → border-color
 *   data-bs       sides drawn, from t r b l       → border-style, side by side (left/right are physical)
 *   data-halign   start | center | end            → text-align (start/end become left/right on the way out)
 *   data-valign   top | middle | bottom           → vertical-align
 *
 * The editor side: resources/js/filament/rich-content-plugins/table-format.js.
 */
class TableFormatExtension extends Extension
{
    /**
     * @var string
     */
    public static $name = 'tableFormat';

    /** The widths offered, in px; anything else is dropped. */
    public const WIDTHS = [0, 0.5, 1, 1.5, 2, 3];

    /** The sides a border can be drawn on, in CSS order: top, right, bottom, left. */
    public const SIDES = ['t' => 'top', 'r' => 'right', 'b' => 'bottom', 'l' => 'left'];

    public const HORIZONTAL = ['start', 'center', 'end'];

    public const VERTICAL = ['top', 'middle', 'bottom'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function addGlobalAttributes(): array
    {
        return [[
            'types' => ['tableCell', 'tableHeader'],
            'attributes' => [
                'data-bw' => [
                    'default' => null,
                    'parseHTML' => fn ($DOMNode) => self::width($DOMNode->getAttribute('data-bw')),
                    'renderHTML' => function ($attributes): array {
                        $width = self::width(self::value($attributes, 'data-bw'));

                        if ($width === null) {
                            return [];
                        }

                        return ['data-bw' => $width, 'style' => $width === '0' ? 'border-width: 0; border-style: none' : "border-width: {$width}px; border-style: solid"];
                    },
                ],
                'data-bc' => [
                    'default' => null,
                    'parseHTML' => fn ($DOMNode) => self::colour($DOMNode->getAttribute('data-bc')),
                    'renderHTML' => function ($attributes): array {
                        $colour = self::colour(self::value($attributes, 'data-bc'));

                        return $colour ? ['data-bc' => $colour, 'style' => 'border-color: '.$colour] : [];
                    },
                ],
                'data-bs' => [
                    'default' => null,
                    'parseHTML' => fn ($DOMNode) => self::sides($DOMNode->getAttribute('data-bs')),
                    'renderHTML' => function ($attributes): array {
                        $sides = self::sides(self::value($attributes, 'data-bs'));

                        if ($sides === null) {
                            return [];
                        }

                        $style = implode(' ', array_map(fn (string $side): string => str_contains($sides, $side) ? 'solid' : 'none', array_keys(self::SIDES)));

                        return ['data-bs' => $sides, 'style' => 'border-style: '.$style];
                    },
                ],
                'data-halign' => [
                    'default' => null,
                    'parseHTML' => fn ($DOMNode) => self::oneOf($DOMNode->getAttribute('data-halign'), self::HORIZONTAL),
                    'renderHTML' => function ($attributes): array {
                        $align = self::oneOf(self::value($attributes, 'data-halign'), self::HORIZONTAL);

                        return $align ? ['data-halign' => $align, 'style' => 'text-align: '.$align] : [];
                    },
                ],
                'data-valign' => [
                    'default' => null,
                    'parseHTML' => fn ($DOMNode) => self::oneOf($DOMNode->getAttribute('data-valign'), self::VERTICAL),
                    'renderHTML' => function ($attributes): array {
                        $align = self::oneOf(self::value($attributes, 'data-valign'), self::VERTICAL);

                        return $align ? ['data-valign' => $align, 'style' => 'vertical-align: '.$align] : [];
                    },
                ],
            ],
        ]];
    }

    private static function value(mixed $attributes, string $name): mixed
    {
        return is_array($attributes) ? ($attributes[$name] ?? null) : ($attributes->{$name} ?? null);
    }

    /** One of the widths offered, as it is written. */
    private static function width(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        foreach (self::WIDTHS as $width) {
            if ((float) $value === (float) $width) {
                return (string) $width;
            }
        }

        return null;
    }

    /**
     * The sides drawn, as the letters t r b l in that order — or null for all
     * four (the default, which is not stored) and for anything else.
     */
    private static function sides(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^[trbl]{1,4}$/', $value)) {
            return null;
        }

        $sides = implode('', array_filter(array_keys(self::SIDES), fn (string $side): bool => str_contains($value, $side)));

        return $sides === 'trbl' ? null : $sides;
    }

    /** Only a #rrggbb colour reaches the style attribute. */
    private static function colour(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
