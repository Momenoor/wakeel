<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A saved signature block: a box (width × height mm, aligned left,
 * centre or right on the letter's line) with elements placed freely in it
 * — the letterhead's signature and stamp, images of its own, and text
 * lines (placeholders welcome: {{matter.experts}}). Images may overlap the
 * text and each other; later elements are drawn on top of earlier ones,
 * and text always over the images.
 *
 * An element: {type: signature|stamp|image|text, x, y (mm from the box's
 * top-left), height (images, mm), width (text, mm), content (text, or the
 * image's path), font_size, bold, align, color}.
 *
 * Dropped into a template from the editor's blocks menu (see
 * App\Services\MMS\Letters\Blocks\SavedSignatureBlock).
 */
#[Fillable('name', 'width', 'height', 'align', 'elements')]
class SignatureLayout extends Model
{
    public const DISK = 'public';

    public const DIRECTORY = 'signature-layouts';

    public const ELEMENT_TYPES = ['signature', 'stamp', 'image', 'text'];

    public function casts(): array
    {
        return [
            'width' => 'float',
            'height' => 'float',
            'elements' => 'array',
        ];
    }

    /**
     * What a letter keeps of it: the layout as it is now, so a letter
     * already issued doesn't change when the block is edited.
     *
     * @return array{width: float, height: float, align: string, elements: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        return [
            'width' => (float) $this->width,
            'height' => (float) $this->height,
            'align' => (string) ($this->align ?: 'left'),
            'elements' => array_values($this->elements ?? []),
        ];
    }

    /**
     * The starting point of a new block: the signature and the stamp side
     * by side, overlapping a little, under the expert's title and name.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultElements(): array
    {
        return [
            ['type' => 'text', 'x' => 0, 'y' => 0, 'width' => 80, 'content' => 'الخبير', 'font_size' => 12, 'bold' => true, 'align' => 'center', 'color' => '#111827'],
            ['type' => 'text', 'x' => 0, 'y' => 6, 'width' => 80, 'content' => '{{matter.experts}}', 'font_size' => 12, 'bold' => false, 'align' => 'center', 'color' => '#111827'],
            ['type' => 'signature', 'x' => 6, 'y' => 13, 'height' => 25],
            ['type' => 'stamp', 'x' => 40, 'y' => 10, 'height' => 32],
        ];
    }

    public static function file(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $file = Storage::disk(self::DISK)->path($path);

        return is_file($file) ? $file : null;
    }

    public static function url(?string $path): ?string
    {
        return filled($path) ? Storage::disk(self::DISK)->url($path) : null;
    }
}
