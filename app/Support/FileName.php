<?php

namespace App\Support;

/**
 * A name a download can carry: no characters Windows refuses in a file name
 * (\ / : * ? " < > |) or control characters, and one space at a time. A
 * download named with a slash ("محضر 1 — 30/09/2026.pdf") is refused outright
 * ("The filename and the fallback cannot contain the "/" and "\" characters").
 */
class FileName
{
    public static function safe(string $name, string $fallback = 'file'): string
    {
        $name = trim((string) preg_replace(['/[\\\\\/:"*?<>|\x00-\x1F\x7F]+/u', '/\s+/u'], ' ', mb_scrub($name, 'UTF-8')));

        return $name !== '' ? $name : $fallback;
    }
}
