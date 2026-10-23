<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Finding employees by what is known of them (global search, Ctrl+K): their name — as a party, in Arabic or as
 * shown —, employee number, phone or email (ContactSearch), Emirates ID (with or without its dashes) or passport.
 * Applied to a query on parties; the employee, their requests and their leave are found through it.
 */
final class EmployeeSearch
{
    /** An employee's requests (a query on them — employee requests, leave): by the employee, or their own number. */
    public static function requests(Builder $requests, string $search): void
    {
        $number = RecordNumber::parse($search);

        $requests->where(fn (Builder $query) => $query
            ->whereHas('party', fn (Builder $party) => self::apply($party, $search))
            ->when($number !== null, fn (Builder $query) => $query->orWhere($query->qualifyColumn('id'), $number)));
    }

    public static function apply(Builder $parties, string $search): void
    {
        $search = trim($search);
        $like = '%'.$search.'%';
        $contact = ContactSearch::parse($search);
        // An Emirates ID typed as its digits, or with its dashes, matches it kept either way.
        $digits = preg_replace('/\D/', '', strtr($search, MatterSearch::DIGITS));

        $parties->where(function (Builder $query) use ($contact, $like, $digits): void {
            if ($contact) {
                $query->where(fn (Builder $party) => ContactSearch::apply($party, $contact));
            } else {
                $query->where('name', 'like', $like);
            }

            $query->orWhereHas('employeeProfile', fn (Builder $profile) => $profile
                ->where('employee_no', 'like', $like)
                ->orWhere('display_name', 'like', $like)
                ->orWhere('name_ar', 'like', $like)
                ->orWhere('passport_no', 'like', $like)
                ->when(strlen((string) $digits) >= 6, fn (Builder $profile) => $profile
                    ->orWhereRaw("REPLACE(emirates_id_no, '-', '') LIKE ?", ['%'.$digits.'%'])));
        });
    }
}
