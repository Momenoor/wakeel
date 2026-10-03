<?php

namespace App\Services\MMS\Letters;

use Mpdf\Language\LanguageToFontInterface;

/**
 * For a letter in a font without Arabic (Calibri): the PDF draws its Arabic
 * text — found and marked by mPDF — in the standard Arabic font, shaped as
 * usual; everything else stays in the letter's font.
 */
class ArabicInBoutros implements LanguageToFontInterface
{
    public function getLanguageOptions($llcc, $adobeCJK): array
    {
        $tags = explode('-', strtolower((string) $llcc));
        $arabic = in_array($tags[0], ['ar', 'ara', 'fa', 'fas', 'per', 'ur', 'urd'], true)
            || in_array('arab', array_slice($tags, 1), true);

        // [the font, whether a core PDF font will do]; '' leaves the font.
        return [$arabic ? LetterPdf::FONT : '', false];
    }
}
