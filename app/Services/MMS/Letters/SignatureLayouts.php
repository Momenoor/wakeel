<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Models\SignatureLayout;
use App\Services\MMS\Letters\Blocks\SavedSignatureBlock;

/**
 * Saved signature blocks in a letter: the editor stores each as
 * <div data-type="customBlock" data-id="saved_signature"
 * data-config="{layout_id, snapshot?}">.
 *
 * The images (signature, stamp, others) are layered into one picture of
 * the box — over each other as designed — and the text lines are set on
 * top of it. mPDF can't place things freely inside flowing text, but it
 * can draw a box's background: the box is the block, the picture its
 * background, the lines its text. Word gets the same picture floating
 * behind its lines (LetterDocx), an email the picture under them.
 */
class SignatureLayouts
{
    /** Pixels per millimetre of the layered picture — about 300 dpi. */
    private const PX_PER_MM = 12;

    /**
     * Each block in a letter keeps the layout as it is now, so editing the
     * saved block later never changes a letter already issued.
     */
    public static function freeze(string $html): string
    {
        return self::eachBlock($html, function (array $config, string $block): string {
            if (isset($config['snapshot']) || ! ($layout = SignatureLayout::find($config['layout_id'] ?? null))) {
                return $block;
            }

            $config['snapshot'] = $layout->snapshot();

            return '<div data-type="customBlock" data-config="'.e(json_encode($config, JSON_UNESCAPED_UNICODE)).'" data-id="'.SavedSignatureBlock::ID.'"></div>';
        });
    }

    /**
     * Every block as letter HTML: a box of the layout's size, its picture
     * as the background, its lines over it. $contentWidth: the width the
     * letter's text runs in (mm), to align the box on its line.
     */
    public static function expand(string $html, Letterhead $letterhead, float $contentWidth): string
    {
        return self::eachBlock($html, function (array $config) use ($letterhead, $contentWidth): string {
            $layout = self::layout($config);

            return $layout ? self::boxHtml($layout, $letterhead, $contentWidth) : '';
        });
    }

    /**
     * For an email, which can't layer: the lines, then the picture.
     */
    public static function forEmail(string $html): string
    {
        return preg_replace_callback(
            '/<div data-sign-layout="([^"]*)"[^>]*>(.*?)<\/div>/su',
            function (array $m): string {
                $box = json_decode((string) base64_decode($m[1]), true) ?: [];
                $lines = preg_replace('/ style="[^"]*"/', '', $m[2]);

                return $lines.(filled($box['image'] ?? null)
                    ? '<p><img src="'.e($box['image']).'" style="width: '.(float) $box['width'].'mm; max-width: 100%;" /></p>'
                    : '');
            },
            $html,
        ) ?? $html;
    }

    /**
     * The block in the editor (and the designer): the elements placed with
     * CSS, the letterhead's own signature and stamp.
     *
     * @param  array{width: float, height: float, align?: string, elements: list<array<string, mixed>>}  $layout
     */
    public static function previewHtml(array $layout, ?Letterhead $letterhead = null): string
    {
        $letterhead ??= Letterhead::default() ?? Letterhead::fallback();
        $width = max(10.0, (float) $layout['width']);
        $height = max(10.0, (float) $layout['height']);
        $percent = fn (float $mm, float $of): string => round($mm / $of * 100, 3).'%';
        $html = '';

        // Images first, in order; text over them.
        foreach (self::ordered($layout['elements']) as $element) {
            $style = 'position: absolute; left: '.$percent((float) ($element['x'] ?? 0), $width).'; top: '.$percent((float) ($element['y'] ?? 0), $height).';';

            if (($element['type'] ?? null) === 'text') {
                $html .= '<div style="'.$style.' width: '.$percent((float) ($element['width'] ?? $width), $width).'; font-size: '.((float) ($element['font_size'] ?? 12) * 0.35 / $width * 100).'cqw; text-align: '.e(self::align($element['align'] ?? 'center')).'; color: '.e($element['color'] ?? '#111827').'; font-weight: '.(! empty($element['bold']) ? 700 : 400).'; line-height: 1.35; white-space: nowrap;">'.e((string) ($element['content'] ?? '')).'</div>';

                continue;
            }

            $url = self::imageUrl($element, $letterhead);
            $html .= $url
                ? '<img src="'.e($url).'" alt="" style="'.$style.' height: '.$percent((float) ($element['height'] ?? 20), $height).'; width: auto;" />'
                : '<div style="'.$style.' height: '.$percent((float) ($element['height'] ?? 20), $height).'; aspect-ratio: 1.6; border: 1px dashed #9ca3af; border-radius: 6px; font-size: 11px; color: #6b7280; display: flex; align-items: center; justify-content: center;">'.e(self::typeLabel($element['type'] ?? '')).'</div>';
        }

        $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][self::align($layout['align'] ?? 'left')];

        return '<div style="display: flex; justify-content: '.$justify.';"><div style="position: relative; width: min(100%, '.($width * 4).'px); aspect-ratio: '.$width.' / '.$height.'; container-type: inline-size; overflow: hidden;">'.$html.'</div></div>';
    }

    /**
     * The layered picture of a layout's images: a PNG the size of the box,
     * transparent where nothing is drawn. Made once per layout and
     * letterhead images, then reused.
     *
     * @param  array{width: float, height: float, elements: list<array<string, mixed>>}  $layout
     */
    public static function picture(array $layout, Letterhead $letterhead): ?string
    {
        $images = collect(self::ordered($layout['elements']))
            ->reject(fn (array $element) => ($element['type'] ?? null) === 'text')
            ->map(fn (array $element) => [...$element, 'file' => self::imageFile($element, $letterhead)])
            ->filter(fn (array $element) => $element['file'] !== null)
            ->values();

        if ($images->isEmpty() || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $key = md5(json_encode([
            $layout['width'], $layout['height'],
            $images->map(fn (array $e) => [$e['x'] ?? 0, $e['y'] ?? 0, $e['height'] ?? 0, $e['file'], @filemtime($e['file'])])->all(),
        ]));
        $path = storage_path('app/signature-layouts/'.$key.'.png');

        if (is_file($path)) {
            return $path;
        }

        $canvas = imagecreatetruecolor(
            max(1, (int) round((float) $layout['width'] * self::PX_PER_MM)),
            max(1, (int) round((float) $layout['height'] * self::PX_PER_MM)),
        );
        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        // Each image over the ones before it.
        imagealphablending($canvas, true);

        foreach ($images as $element) {
            $image = @imagecreatefromstring((string) file_get_contents($element['file']));
            if (! $image) {
                continue;
            }

            $targetHeight = max(1, (int) round((float) ($element['height'] ?? 20) * self::PX_PER_MM));
            $targetWidth = max(1, (int) round(imagesx($image) * $targetHeight / max(1, imagesy($image))));

            imagecopyresampled(
                $canvas, $image,
                (int) round((float) ($element['x'] ?? 0) * self::PX_PER_MM), (int) round((float) ($element['y'] ?? 0) * self::PX_PER_MM),
                0, 0, $targetWidth, $targetHeight, imagesx($image), imagesy($image),
            );
            imagedestroy($image);
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        imagepng($canvas, $path);
        imagedestroy($canvas);

        return $path;
    }

    /**
     * The layout a block shows: the copy the letter keeps, else the saved
     * block as it is now.
     *
     * @param  array<string, mixed>  $config
     * @return array{width: float, height: float, align: string, elements: list<array<string, mixed>>}|null
     */
    public static function layout(array $config): ?array
    {
        if (is_array($config['snapshot'] ?? null)) {
            return $config['snapshot'];
        }

        return SignatureLayout::find($config['layout_id'] ?? null)?->snapshot();
    }

    /**
     * @param  array{width: float, height: float, align?: string, elements: list<array<string, mixed>>}  $layout
     */
    private static function boxHtml(array $layout, Letterhead $letterhead, float $contentWidth): string
    {
        $width = min(max(10.0, (float) $layout['width']), $contentWidth);
        $height = max(10.0, (float) $layout['height']);
        $offset = match (self::align($layout['align'] ?? 'left')) {
            'center' => ($contentWidth - $width) / 2,
            'right' => $contentWidth - $width,
            default => 0.0,
        };
        $picture = self::picture($layout, $letterhead);

        // The lines, top to bottom: each placed by its distance from the
        // one before (mPDF has no positioning inside the flow).
        $lines = '';
        $cursor = 0.0;

        foreach (collect($layout['elements'])->where('type', 'text')->sortBy(fn ($e) => (float) ($e['y'] ?? 0)) as $text) {
            $size = (float) ($text['font_size'] ?? 12);
            $x = max(0.0, (float) ($text['x'] ?? 0));
            $lineWidth = min($width - $x, (float) ($text['width'] ?? $width));
            $top = max(0.0, (float) ($text['y'] ?? 0) - $cursor);
            $content = e((string) ($text['content'] ?? ''));

            $lines .= '<p style="margin: '.round($top, 2).'mm '.round(max(0, $width - $x - $lineWidth), 2).'mm 0 '.round($x, 2).'mm; font-size: '.$size.'pt; line-height: 1.35; text-align: '.e(self::align($text['align'] ?? 'center')).'; color: '.e($text['color'] ?? '#111827').';">'
                .(! empty($text['bold']) ? '<strong>'.$content.'</strong>' : $content)
                .'</p>';

            $cursor = max($cursor, (float) ($text['y'] ?? 0)) + $size * 0.3528 * 1.35;
        }

        // Read back by Word and email (the lines are in the box).
        $box = base64_encode((string) json_encode(['image' => $picture, 'width' => $width, 'height' => $height, 'offset' => $offset]));
        $background = $picture ? ' background: url(\''.e($picture).'\') no-repeat 0 0; background-image-resize: 6;' : '';

        return '<div data-sign-layout="'.$box.'" style="width: '.round($width, 2).'mm; margin: 2mm 0 2mm '.round($offset, 2).'mm; padding: 0 0 '.round(max(0, $height - $cursor), 2).'mm 0; page-break-inside: avoid;'.$background.'">'
            .($lines !== '' ? $lines : '<p style="margin: 0;">&#160;</p>')
            .'</div>';
    }

    /**
     * Each saved signature block in the HTML, replaced.
     *
     * @param  \Closure(array<string, mixed>, string): string  $replace
     */
    private static function eachBlock(string $html, \Closure $replace): string
    {
        if (! str_contains($html, SavedSignatureBlock::ID)) {
            return $html;
        }

        return preg_replace_callback(
            '/<div\b[^>]*data-type="customBlock"[^>]*>.*?<\/div>/su',
            function (array $m) use ($replace): string {
                if (! preg_match('/data-id="'.preg_quote(SavedSignatureBlock::ID, '/').'"/', $m[0])) {
                    return $m[0];
                }

                $config = preg_match('/data-config="([^"]*)"/', $m[0], $c)
                    ? json_decode(html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true)
                    : null;

                return $replace(is_array($config) ? $config : [], $m[0]);
            },
            $html,
        ) ?? $html;
    }

    /**
     * Images in the order they're stacked (the first at the bottom); text
     * lines after them, always on top.
     *
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $elements): array
    {
        return collect($elements)
            ->sortBy(fn (array $element, int $i) => (($element['type'] ?? null) === 'text' ? 1000 : 0) + $i)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private static function imageFile(array $element, Letterhead $letterhead): ?string
    {
        return match ($element['type'] ?? null) {
            'signature' => $letterhead->file($letterhead->signature_image),
            'stamp' => $letterhead->file($letterhead->stamp_image),
            'image' => SignatureLayout::file($element['content'] ?? null),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private static function imageUrl(array $element, Letterhead $letterhead): ?string
    {
        return match ($element['type'] ?? null) {
            'signature' => $letterhead->signature_image ? $letterhead->url($letterhead->signature_image) : null,
            'stamp' => $letterhead->stamp_image ? $letterhead->url($letterhead->stamp_image) : null,
            'image' => SignatureLayout::url($element['content'] ?? null),
            default => null,
        };
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'signature' => __('Signature'),
            'stamp' => __('Stamp'),
            'image' => __('Image'),
            default => __('Text'),
        };
    }

    private static function align(mixed $align): string
    {
        return in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    }
}
