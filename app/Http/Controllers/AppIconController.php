<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use GdImage;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The installed app's icons (the manifest's): the office's icon — else its logo — on a white square, 192 and
 * 512 pixels as browsers ask, and a "maskable" one with room around it for the round and rounded shapes phones
 * cut it to. Made once for each icon uploaded, and kept.
 */
class AppIconController extends Controller
{
    public const SIZES = [192, 512];

    public function __invoke(int $size, string $purpose): BinaryFileResponse|RedirectResponse
    {
        abort_unless(in_array($size, self::SIZES, true) && in_array($purpose, ['any', 'maskable'], true), 404);

        $source = self::source();

        // No picture to draw from, or no GD on the server: the icon as it is.
        if ($source === null || ! function_exists('imagecreatefromstring')) {
            return redirect()->away(Branding::faviconUrl());
        }

        $file = storage_path('app/app-icons/'.self::version().'-'.$size.'-'.$purpose.'.png');

        if (! is_file($file)) {
            @mkdir(dirname($file), 0755, true);
            if (! self::draw($source, $size, $purpose === 'maskable' ? 0.2 : 0.08, $file)) {
                return redirect()->away(Branding::faviconUrl());
            }
        }

        return response()->file($file, [
            'Content-Type' => 'image/png',
            // The address changes with the icon (?v=…), so it may be kept long.
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /** Changes when another icon or logo is uploaded — in the icons' addresses, so browsers fetch the new one. */
    public static function version(): string
    {
        $source = self::source();

        return $source === null ? '0' : substr(md5($source.'|'.@filemtime($source)), 0, 10);
    }

    /** The icon, else the logo — a picture GD reads (not an SVG). */
    private static function source(): ?string
    {
        foreach ([Branding::faviconFile(), Branding::logoFile()] as $file) {
            if ($file !== null && ! str_ends_with(strtolower($file), '.svg')) {
                return $file;
            }
        }

        return null;
    }

    /** The picture, its shape kept, in the middle of a white square with $padding of it left around. */
    private static function draw(string $source, int $size, float $padding, string $target): bool
    {
        $picture = @imagecreatefromstring((string) file_get_contents($source));
        if (! $picture instanceof GdImage) {
            return false;
        }

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);

        $room = (int) round($size * (1 - 2 * $padding));
        $w = imagesx($picture);
        $h = imagesy($picture);
        $scale = min($room / max(1, $w), $room / max(1, $h));
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));

        imagecopyresampled($canvas, $picture, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);

        return imagepng($canvas, $target, 9);
    }
}
