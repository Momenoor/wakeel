<?php

namespace App\Filament\Forms\Components;

use App\Models\SignatureStamp;
use Closure;
use Filament\Forms\Components\Field;

/**
 * A pad to draw on with a finger, a pen or the mouse (the signature_pad library). Its state is the drawing as
 * a PNG data URL — transparent, cropped to the ink — or null while nothing is drawn. Turn it into a file
 * with decode():
 *
 *   SignaturePad::make('drawing')->required()
 *   SignaturePad::make('drawing')->penColor('#000000')->padHeight(160)
 *
 *   $png = SignaturePad::decode($data['drawing']);   // the PNG's bytes, or null when it is not one
 */
class SignaturePad extends Field
{
    protected string $view = 'filament.forms.components.signature-pad';

    protected string|Closure $penColor = '#0b1f66';

    protected int|Closure $padHeight = 200;

    protected function setUp(): void
    {
        parent::setUp();

        // Whatever the browser sent must really be a PNG — never taken for one on its word.
        $this->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && self::decode($value) === null) {
                $fail(__('The drawing could not be read. Please draw it again.'));
            }
        });
    }

    /** The ink's colour (dark blue, like a pen, unless told otherwise). */
    public function penColor(string|Closure $color): static
    {
        $this->penColor = $color;

        return $this;
    }

    public function getPenColor(): string
    {
        return (string) $this->evaluate($this->penColor);
    }

    /** How tall the pad is, in pixels; it is as wide as the form. */
    public function padHeight(int|Closure $height): static
    {
        $this->padHeight = $height;

        return $this;
    }

    public function getPadHeight(): int
    {
        return max(80, (int) $this->evaluate($this->padHeight));
    }

    /**
     * A PNG data URL's image, or null when it is not a PNG (or is bigger than a library image may be).
     */
    public static function decode(mixed $dataUrl, int $maxKb = SignatureStamp::MAX_KB): ?string
    {
        $prefix = 'data:image/png;base64,';

        if (! is_string($dataUrl) || ! str_starts_with($dataUrl, $prefix) || strlen($dataUrl) > ($maxKb * 1024 * 4 / 3) + 64) {
            return null;
        }

        $bytes = base64_decode(substr($dataUrl, strlen($prefix)), true);

        if ($bytes === false || strlen($bytes) > $maxKb * 1024 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        $size = @getimagesizefromstring($bytes);

        return $size !== false && $size[2] === IMAGETYPE_PNG ? $bytes : null;
    }
}
