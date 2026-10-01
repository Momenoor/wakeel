<?php

namespace App\Support;

/**
 * Alignment in left/right terms.
 *
 * The rich editor aligns to the text's start or end (text-align: start /
 * end). mPDF, Word's HTML import and Outlook only know left and right, so
 * whatever leaves the app is written with the physical sides: in Arabic
 * the start is the right.
 */
class TextDirection
{
    /**
     * text-align: start / end as right / left (or left / right).
     */
    public static function physicalAlignment(string $html, bool $rtl): string
    {
        $sides = $rtl ? ['start' => 'right', 'end' => 'left'] : ['start' => 'left', 'end' => 'right'];

        return preg_replace_callback(
            '/text-align:\s*(start|end)\b/i',
            fn (array $m): string => 'text-align: '.$sides[strtolower($m[1])],
            $html,
        ) ?? $html;
    }
}
