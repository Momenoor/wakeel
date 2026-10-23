<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Searching by an email or a phone number (global search, Ctrl+K): a party with it, and the matters it is a
 * main party of. A phone is matched by its last nine digits, so "050 111 2233", "0501112233" and
 * "+971501112233" all find a party kept as any of them. Both are lists on the party (JSON), read as text.
 */
final class ContactSearch
{
    /** Digits a phone number needs to be taken for one: a landline has 9 (04 123 4567); "2024 4321" is a matter. */
    private const MIN_DIGITS = 9;

    /**
     * What was typed, as a contact: ['email', 'name@x.ae'] or ['phone', '501112233']; null when it is neither.
     *
     * @return array{0: 'email'|'phone', 1: string}|null
     */
    public static function parse(string $search): ?array
    {
        $search = trim($search);

        if (str_contains($search, '@')) {
            return ['email', mb_strtolower($search)];
        }

        $digits = preg_replace('/\D/', '', strtr($search, MatterSearch::DIGITS));

        return preg_match('/^[\d٠-٩\s+\-().]+$/u', $search) && strlen((string) $digits) >= self::MIN_DIGITS
            ? ['phone', substr((string) $digits, -9)]
            : null;
    }

    /**
     * The parties (a query on them) with that contact.
     *
     * @param  array{0: 'email'|'phone', 1: string}  $contact
     */
    public static function apply(Builder $parties, array $contact): void
    {
        [$kind, $value] = $contact;
        $column = $parties->qualifyColumn($kind);

        $parties->whereRaw("LOWER(CAST({$column} AS CHAR)) LIKE ?", ['%'.$value.'%']);
    }
}
