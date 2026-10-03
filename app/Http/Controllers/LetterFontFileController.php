<?php

namespace App\Http\Controllers;

use App\Models\LetterFont;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An uploaded font's file, for the browsers of signed-in users when it is
 * the system's font (a licensed font is never served to the public).
 */
class LetterFontFileController extends Controller
{
    public function __invoke(LetterFont $font, string $weight): StreamedResponse
    {
        $path = ($font->files ?? [])[$weight] ?? null;
        abort_unless(array_key_exists($weight, LetterFont::WEIGHTS) && filled($path) && Storage::disk(LetterFont::DISK)->exists($path), 404);

        return Storage::disk(LetterFont::DISK)->response($path, basename($path), [
            'Content-Type' => 'font/ttf',
            // The address carries the font's version: cached for long.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
