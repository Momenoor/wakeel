<?php

namespace App\Support;

/**
 * A party's name as it is compared: two names that are one party's however typed — spaces and tabs, case,
 * hamza and taa marbuta, diacritics, punctuation, "ذ.م.م" or "LLC" — have the same key (key()), and two that
 * differ by a letter or two are close (close()). Kept on the party (parties.name_key) to find a duplicate before
 * it is made.
 */
final class PartyNames
{
    /** The name as kept: no spaces about it, one space between words (a tab or a run of spaces in it made one). */
    public static function tidy(?string $name): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{FEFF}]+/u', ' ', (string) $name));
    }

    public static function key(?string $name): string
    {
        $key = mb_strtolower(self::tidy($name));
        // Diacritics and the tatweel.
        $key = (string) preg_replace('/[\x{064B}-\x{0652}\x{0640}\x{0670}]/u', '', $key);
        $key = strtr($key, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
        // Punctuation as a space.
        $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);
        // The company's form ("ذ م م", "ش م ع", "LLC" …) says nothing of which company.
        $key = (string) preg_replace('/(?<!\S)(ذ م م|ذمم|ش م ع|ش م خ|ش ذ م م|م د م ح|llc|l l c|fze|fzco|fzc|fz llc|ltd|limited|pjsc|psc|co|company)(?!\S)/u', ' ', $key);
        $key = trim((string) preg_replace('/\s+/u', ' ', $key));
        // "عبد الله" and "عبدالله", "ابو بكر" and "ابوبكر".
        $key = (string) preg_replace('/(?<!\S)(عبد|ابو) (?=\S)/u', '$1', $key);

        return $key;
    }

    /**
     * Whether two keys are a letter or two apart (a misspelling): none apart for a short name, one for one up to
     * ten letters, two beyond.
     */
    public static function close(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        $length = min(mb_strlen($a), mb_strlen($b));
        $allowed = $length < 6 ? 0 : ($length <= 10 ? 1 : 2);

        return $allowed > 0 && abs(mb_strlen($a) - mb_strlen($b)) <= $allowed && self::distance($a, $b) <= $allowed;
    }

    /** Levenshtein, by letter (not byte): Arabic is two bytes a letter. */
    public static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $previous = range(0, count($y));

        foreach ($x as $i => $cx) {
            $current = [$i + 1];

            foreach ($y as $j => $cy) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($cx === $cy ? 0 : 1));
            }

            $previous = $current;
        }

        return $previous[count($y)];
    }
}
