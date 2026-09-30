<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class HtmlFormatter
{
    /**
     * An event description, ready for an entry that renders with ->html().
     *
     * Descriptions synced from Outlook are HTML: shown as HTML, cleaned by
     * Filament's sanitizer (Symfony HtmlSanitizer) — no scripts, event
     * handlers or javascript: links — after dropping the document's
     * <head>/<style>/<script> blocks. Plain text is escaped, keeps its line
     * breaks, and has bare URLs turned into links. Either way the state is
     * user-supplied, so it is never rendered raw.
     */
    public static function linkify(?string $state): ?string
    {
        if (blank($state)) {
            return $state;
        }

        if ($state !== strip_tags($state)) {
            return self::cleanHtml($state);
        }

        $safeState = e($state);

        $pattern = '~(?<!@)\b(?:https?://|www\.)[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))~';

        $linked = preg_replace_callback($pattern, function (array $matches): string {
            $url = $matches[0];
            $href = str_starts_with($url, 'www.') ? "https://{$url}" : $url;
            $class = str_contains($url, 'teams.microsoft.com')
                ? 'text-primary-600 font-bold underline'
                : 'text-primary-600 underline';

            return '<a href="'.$href.'" target="_blank" rel="noopener noreferrer" class="'.$class.'">'.$url.'</a>';
        }, $safeState);

        return nl2br($linked);
    }

    private static function cleanHtml(string $html): string
    {
        $html = preg_replace('~<(head|style|script|title)\b[^>]*>.*?</\1\s*>~is', '', $html) ?? '';

        if (preg_match('~<body\b[^>]*>(.*)</body\s*>~is', $html, $body)) {
            $html = $body[1];
        }

        $html = Str::sanitizeHtml($html);

        // Links open in a new tab, like the ones made from plain text.
        return preg_replace('~<a\s(?![^>]*\btarget=)~i', '<a target="_blank" rel="noopener noreferrer" ', $html) ?? $html;
    }
}
