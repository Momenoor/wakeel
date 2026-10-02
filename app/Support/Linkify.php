<?php

namespace App\Support;

/**
 * Plain text shown as HTML with its links clickable: web addresses
 * (http://, https://, www.) and email addresses. Everything else is
 * escaped — what people type is never taken as HTML.
 */
class Linkify
{
    /** Punctuation that ends a sentence, not the address before it. */
    private const TRAILING = '.,;:!?)]}"\'،؛؟»';

    public static function html(?string $text, string $style = 'text-decoration: underline; color: inherit;'): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        $pattern = '~(?<url>\b(?:https?://|www\.)[^\s<>"]+)|(?<email>\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b)~u';
        $html = '';
        $offset = 0;

        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$found, $at] = $match[0];
            $isUrl = isset($match['url']) && $match['url'][1] !== -1 && $match['url'][0] !== '';

            // "see https://x.ae." — the full stop is the sentence's.
            $trailing = '';
            if ($isUrl) {
                $trimmed = rtrim($found, self::TRAILING);
                // A closing bracket the address itself opened stays
                // (https://en.wikipedia.org/wiki/Dubai_(city)).
                if (substr($found, strlen($trimmed), 1) === ')' && substr_count($trimmed, '(') > substr_count($trimmed, ')')) {
                    $trimmed .= ')';
                }
                $trailing = substr($found, strlen($trimmed));
                $found = $trimmed;
            }

            $href = $isUrl
                ? (str_starts_with(strtolower($found), 'www.') ? 'https://'.$found : $found)
                : 'mailto:'.$found;

            $html .= e(substr($text, $offset, $at - $offset))
                .'<a href="'.e($href).'"'.($isUrl ? ' target="_blank" rel="noopener noreferrer"' : '').' dir="ltr" style="'.e($style).' overflow-wrap: anywhere;">'.e($found).'</a>'
                .e($trailing);
            $offset = $at + strlen($match[0][0]);
        }

        return $html.e(substr($text, $offset));
    }
}
