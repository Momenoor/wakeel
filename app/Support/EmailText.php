<?php

namespace App\Support;

/**
 * An email's HTML as the words a reader sees, in lines — what the system reads a matter number, a name or a
 * salutation from. Never what is not shown: the head, a style sheet (Outlook's "P {margin-top:0;…}"), scripts,
 * comments.
 */
class EmailText
{
    public static function plain(string $html): string
    {
        $html = (string) preg_replace(['~<(style|script|head|title)\b.*?</\1\s*>~is', '~<!--.*?-->~s'], ' ', $html);
        // Each break, paragraph, block, row and item a line of its own.
        $html = (string) preg_replace('~<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[ \t]+/u', ' ', str_replace(["\u{00A0}", "\r"], [' ', ''], $text)));
    }
}
