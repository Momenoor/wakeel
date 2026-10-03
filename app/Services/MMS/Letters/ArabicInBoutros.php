<?php

namespace App\Services\MMS\Letters;

use Mpdf\Language\LanguageToFontInterface;

/**
 * A letter's Arabic text — found and marked by mPDF — in its Arabic font
 * (the template's own, or the standard one), shaped as usual; everything
 * else stays in the letter's English font.
 */
class ArabicInBoutros implements LanguageToFontInterface
{
    public function __construct(private readonly string $font = LetterPdf::FONT) {}

    public function getLanguageOptions($llcc, $adobeCJK): array
    {
        $tags = explode('-', strtolower((string) $llcc));
        $arabic = in_array($tags[0], ['ar', 'ara', 'fa', 'fas', 'per', 'ur', 'urd'], true)
            || in_array('arab', array_slice($tags, 1), true);

        // [the font, whether a core PDF font will do]; '' leaves the font.
        return [$arabic ? $this->font : '', false];
    }
}
