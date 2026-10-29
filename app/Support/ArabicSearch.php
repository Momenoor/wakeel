<?php

namespace App\Support;

/**
 * Arabic searched as it is meant, not as it is spelt: أحمد and احمد, علي and على, مهرة and مهره found alike.
 * Every "like" search with Arabic in it compares both sides so (App\Support\Database\SearchesArabic), Filament's own
 * searches too; options a select filters in the browser likewise (filament.partials.arabic-search-script).
 */
final class ArabicSearch
{
    /**
     * Each letter written more than one way, as the one it is searched as. Only these: each costs the search a
     * replace() of every row it reads (marks — tashkeel — and the tatweel, hardly ever in a name, are not).
     */
    public const LETTERS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
        'ى' => 'ي', 'ئ' => 'ي',
        'ؤ' => 'و',
        'ة' => 'ه',
    ];

    public static function normalize(string $text): string
    {
        return strtr($text, self::LETTERS);
    }

    /** The SQL $sql (a column, an expression or a ?) as it is searched. */
    public static function sql(string $sql): string
    {
        foreach (self::LETTERS as $from => $to) {
            $sql = "replace({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }

    /** Whether a where clause is a "like" search for Arabic. */
    public static function searches(array $where): bool
    {
        return str_contains(strtolower((string) ($where['operator'] ?? '')), 'like')
            && is_string($where['value'] ?? null)
            && preg_match('/\p{Arabic}/u', $where['value']) === 1;
    }
}
