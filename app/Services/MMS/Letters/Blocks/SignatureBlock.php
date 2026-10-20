<?php

namespace App\Services\MMS\Letters\Blocks;

/**
 * The letter's sign-off, dropped into any template from the editor's
 * signature blocks menu (set in its dialog: resources/js/rich-text-field.js):
 * the expert's title and name, then the signature and the stamp side by side — each part switched on or off, the whole aligned to
 * one side. The name is the matter's expert, or one typed in.
 *
 * Saved in the template as <div data-type="customBlock" data-id="…"
 * data-config="{…}">; the letter turns it into its HTML (expand()) with
 * {{signature}}, {{stamp}} and {{matter.experts}} still to fill, so it
 * takes the letterhead's images and sizes like any other letter.
 */
class SignatureBlock
{
    public const ID = 'signature_block';

    public static function getId(): string
    {
        return self::ID;
    }

    public static function getLabel(): string
    {
        return __('Signature and stamp');
    }

    /**
     * The block as letter HTML, its placeholders still to fill.
     *
     * @param  array<string, mixed>  $config
     */
    public static function letterHtml(array $config): string
    {
        $config = [...self::defaults(), ...$config];
        $style = ' style="text-align: '.self::align($config['align']).'; margin: 0;"';

        $name = match ($config['expert']) {
            'matter' => '{{matter.experts}}',
            'custom' => e(trim((string) $config['name'])),
            default => '',
        };

        $images = trim(($config['signature'] ? '{{signature}}' : '').' '.($config['stamp'] ? '{{stamp}}' : ''));

        return implode('', array_filter([
            filled($config['title']) ? '<p'.$style.'><strong>'.e(trim((string) $config['title'])).'</strong></p>' : null,
            $name !== '' ? '<p'.$style.'><strong>'.$name.'</strong></p>' : null,
            // Inline, so the signature and the stamp sit side by side. A div,
            // not a paragraph: a lone {{signature}} paragraph is replaced
            // whole by the image, alignment and all.
            $images !== '' ? '<div'.$style.'>'.$images.'</div>' : null,
        ]));
    }

    /**
     * Every signature block in a template's HTML, as letter HTML.
     */
    public static function expand(string $html): string
    {
        if (! str_contains($html, self::getId())) {
            return $html;
        }

        return preg_replace_callback(
            '/<div\b[^>]*data-type="customBlock"[^>]*>.*?<\/div>/su',
            function (array $m): string {
                if (! preg_match('/data-id="'.preg_quote(self::getId(), '/').'"/', $m[0])) {
                    return $m[0];
                }

                $config = preg_match('/data-config="([^"]*)"/', $m[0], $c)
                    ? json_decode(html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true)
                    : null;

                return self::letterHtml(is_array($config) ? $config : []);
            },
            $html,
        ) ?? $html;
    }

    /**
     * @return array{title: string, expert: string, name: string, signature: bool, stamp: bool, align: string}
     */
    private static function defaults(): array
    {
        return ['title' => '', 'expert' => 'matter', 'name' => '', 'signature' => true, 'stamp' => true, 'align' => 'left'];
    }

    private static function align(mixed $align): string
    {
        return in_array($align, ['right', 'center', 'left'], true) ? $align : 'left';
    }
}
