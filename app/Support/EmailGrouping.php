<?php

namespace App\Support;

/**
 * How a letter's or minutes' emails go out: one to each person, one to each
 * party with its representatives (and whoever attended for it), or one to
 * everyone.
 */
final class EmailGrouping
{
    public const SEPARATE = 'separate';

    public const BY_PARTY = 'by_party';

    public const ALL = 'all';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::SEPARATE => __('Separately, to each one'),
            self::BY_PARTY => __('By party, with its representatives'),
            self::ALL => __('All in one email'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            self::SEPARATE => __('Each sees only their own address and is greeted by name.'),
            self::BY_PARTY => __('A party, its representatives and whoever attended on its behalf share one email.'),
            self::ALL => __('Everyone in one email, seeing each other\'s address.'),
        ];
    }

    /** One of the three; true (the older "separately") is by party, false all. */
    public static function from(mixed $value): string
    {
        return match (true) {
            $value === true => self::BY_PARTY,
            $value === false, $value === null => self::ALL,
            in_array($value, [self::SEPARATE, self::BY_PARTY, self::ALL], true) => $value,
            default => self::ALL,
        };
    }
}
