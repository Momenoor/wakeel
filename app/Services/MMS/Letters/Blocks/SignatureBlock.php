<?php

namespace App\Services\MMS\Letters\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The letter's sign-off, dropped into any template from the editor's
 * blocks panel: the expert's title and name, then the signature and the
 * stamp side by side — each part switched on or off, the whole aligned to
 * one side. The name is the matter's expert, or one typed in.
 *
 * Saved in the template as <div data-type="customBlock" data-id="…"
 * data-config="{…}">; the letter turns it into its HTML (expand()) with
 * {{signature}}, {{stamp}} and {{matter.experts}} still to fill, so it
 * takes the letterhead's images and sizes like any other letter.
 */
class SignatureBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'signature_block';
    }

    public static function getLabel(): string
    {
        return __('Signature and stamp');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription(__('The expert\'s name and title, the signature and the stamp, as they sign off the letter.'))
            // A new block starts from the defaults; an existing one from its own settings.
            ->fillForm(fn (array $arguments): array => [...self::defaults(), ...((array) ($arguments['config'] ?? []))])
            ->schema([
                TextInput::make('title')
                    ->label(__('Title above the name'))
                    ->placeholder('الخبير المحاسبي'),
                Radio::make('expert')
                    ->label(__('Name'))
                    ->options([
                        'matter' => __('The matter\'s expert'),
                        'custom' => __('This name'),
                        'none' => __('No name'),
                    ])
                    ->inline()
                    ->live(),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required(fn (Get $get): bool => $get('expert') === 'custom')
                    ->visible(fn (Get $get): bool => $get('expert') === 'custom'),
                Grid::make(3)->schema([
                    Toggle::make('signature')->label(__('Signature')),
                    Toggle::make('stamp')->label(__('Stamp')),
                    Radio::make('align')
                        ->label(__('Position'))
                        ->options(['right' => __('Right'), 'center' => __('Centre'), 'left' => __('Left')]),
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        return self::getLabel();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        $config = [...self::defaults(), ...$config];
        $box = fn (string $label): string => '<span style="display:inline-block;margin:4px;padding:10px 18px;border:1px dashed #9ca3af;border-radius:6px;color:#6b7280;font-size:0.85em;">'.e($label).'</span>';

        $lines = array_filter([
            filled($config['title']) ? '<div>'.e($config['title']).'</div>' : null,
            match ($config['expert']) {
                'matter' => '<div><strong>'.e(__('The matter\'s expert')).'</strong></div>',
                'custom' => '<div><strong>'.e((string) $config['name']).'</strong></div>',
                default => null,
            },
            ($config['signature'] || $config['stamp'])
                ? '<div>'.($config['signature'] ? $box(__('Signature')) : '').($config['stamp'] ? $box(__('Stamp')) : '').'</div>'
                : null,
        ]);

        return '<div style="text-align:'.self::align($config['align']).';">'.implode('', $lines).'</div>';
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
