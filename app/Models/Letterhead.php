<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * The page a letter is printed on: the scanned letterhead as a full-page
 * background (a different one for the pages after the first, if needed),
 * a watermark, the margins the letter text flows between, the signature
 * and stamp, and freely placed elements — the reference number and date
 * top-left, a logo, text boxes, lines, the page number.
 *
 * An element: {type, page: first|all|rest, x, y, width (all mm from the
 * page's top-left), content, font_size, bold, align, color}.
 */
#[Fillable('name', 'is_default', 'orientation', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'other_margin_top', 'other_margin_bottom', 'first_page_background', 'other_pages_background', 'watermark_type', 'watermark_text', 'watermark_image', 'watermark_opacity', 'signature_image', 'signature_height', 'stamp_image', 'stamp_height', 'elements')]
class Letterhead extends Model
{
    public const DISK = 'public';

    public const DIRECTORY = 'letterheads';

    /** A4, in millimetres. */
    public const PAGE_WIDTH = 210;

    public const PAGE_HEIGHT = 297;

    public const ELEMENT_TYPES = ['reference', 'date', 'text', 'image', 'logo', 'line', 'page_number'];

    public function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'elements' => 'array',
            'margin_top' => 'float',
            'margin_right' => 'float',
            'margin_bottom' => 'float',
            'margin_left' => 'float',
            'other_margin_top' => 'float',
            'other_margin_bottom' => 'float',
            'signature_height' => 'float',
            'stamp_height' => 'float',
            'watermark_opacity' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Only one default letterhead.
        static::saved(function (Letterhead $letterhead): void {
            if ($letterhead->is_default) {
                static::query()->whereKeyNot($letterhead->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function templates(): HasMany
    {
        return $this->hasMany(LetterTemplate::class);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first() ?? static::query()->oldest('id')->first();
    }

    /**
     * Plain paper, for when no letterhead has been set up yet: the default
     * margins and elements (reference and date top-left, page number).
     */
    public static function fallback(): self
    {
        return new self([
            'name' => 'Plain',
            'orientation' => 'portrait',
            'margin_top' => 45,
            'margin_right' => 20,
            'margin_bottom' => 30,
            'margin_left' => 20,
            'watermark_type' => 'none',
            'elements' => self::defaultElements(),
        ]);
    }

    /**
     * An uploaded file of this letterhead as a path on disk (mPDF and
     * PHPWord read files, not URLs), or null.
     */
    public function file(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $file = Storage::disk(self::DISK)->path($path);

        return is_file($file) ? $file : null;
    }

    public function url(?string $path): ?string
    {
        return filled($path) ? Storage::disk(self::DISK)->url($path) : null;
    }

    /**
     * The elements a new letterhead starts with: reference number and date
     * top-left, and the page number at the bottom.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * The margins of the pages after the first, in mm. Top and bottom are
     * their own (the first page's when not set); left and right are always
     * the first page's — the PDF engine cannot vary them per page.
     *
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    public function otherPagesMargins(): array
    {
        return [
            'top' => (float) ($this->other_margin_top ?? $this->margin_top),
            'right' => (float) $this->margin_right,
            'bottom' => (float) ($this->other_margin_bottom ?? $this->margin_bottom),
            'left' => (float) $this->margin_left,
        ];
    }

    public static function defaultElements(): array
    {
        return [
            ['type' => 'reference', 'page' => 'first', 'x' => 20, 'y' => 30, 'width' => 80, 'content' => null, 'font_size' => 11, 'bold' => true, 'align' => 'left', 'color' => '#111827'],
            ['type' => 'date', 'page' => 'first', 'x' => 20, 'y' => 36, 'width' => 80, 'content' => null, 'font_size' => 11, 'bold' => false, 'align' => 'left', 'color' => '#111827'],
            ['type' => 'page_number', 'page' => 'all', 'x' => 95, 'y' => 285, 'width' => 20, 'content' => null, 'font_size' => 9, 'bold' => false, 'align' => 'center', 'color' => '#6b7280'],
        ];
    }
}
