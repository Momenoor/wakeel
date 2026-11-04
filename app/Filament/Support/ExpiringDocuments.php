<?php

namespace App\Filament\Support;

use App\Support\Dates;
use Carbon\CarbonInterface;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The documents of a record that expire — an employee's passport, Emirates ID, labour card and residency, a
 * company's trade licence and establishment card — watched the same way wherever they are: a badge for each one
 * expired or due within WINDOW_DAYS in its table (red once expired), a filter for the records with one, a count
 * on the menu, and the bell on the days in WARN_AT (WarnExpiringDocuments).
 *
 * A model names its documents once, as column => label (the label untranslated):
 *
 *   public const EXPIRING = ['passport_expiry' => 'Passport', 'emirates_id_expiry' => 'Emirates ID'];
 *
 *   ExpiringDocuments::column(EmployeeProfile::EXPIRING)        // in the table
 *   ExpiringDocuments::filter(EmployeeProfile::EXPIRING)        // its filter
 *   ExpiringDocuments::badge($query, EmployeeProfile::EXPIRING) // the menu's count
 */
final class ExpiringDocuments
{
    /** How far ahead a document counts as expiring soon. */
    public const WINDOW_DAYS = 60;

    /** The days before a document expires — and the day itself — that the bell rings for it. */
    public const WARN_AT = [60, 30, 7, 0];

    /**
     * The record's documents expired, or expiring within the days given, the soonest first: label (in the
     * user's language) => its expiry.
     *
     * @param  array<string, string>  $documents  column => label
     * @return array<string, CarbonInterface>
     */
    public static function due(Model $record, array $documents, int $within = self::WINDOW_DAYS): array
    {
        $limit = today()->addDays($within);
        $due = [];

        foreach ($documents as $column => $label) {
            $date = $record->getAttribute($column);

            if ($date instanceof CarbonInterface && $date->lessThanOrEqualTo($limit)) {
                $due[__($label)] = $date;
            }
        }

        uasort($due, fn (CarbonInterface $a, CarbonInterface $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $due;
    }

    /**
     * Only the records with a document expired, or expiring within the days given.
     *
     * @param  array<string, string>  $documents
     */
    public static function constrain(Builder $query, array $documents, int $within = self::WINDOW_DAYS): Builder
    {
        $limit = today()->addDays($within)->toDateString();

        return $query->where(function (Builder $query) use ($documents, $limit): void {
            foreach (array_keys($documents) as $column) {
                $query->orWhereDate($column, '<=', $limit);
            }
        });
    }

    /** "Passport — expired 03/11/2026", "Passport — expires today", "Passport — 12/12/2026 (38 days left)". */
    public static function describe(string $label, CarbonInterface $date): string
    {
        $days = (int) today()->diffInDays($date->copy()->startOfDay(), false);

        return match (true) {
            $days < 0 => __(':document — expired :date', ['document' => $label, 'date' => Dates::date($date)]),
            $days === 0 => __(':document — expires today', ['document' => $label]),
            default => __(':document — :date (:days days left)', ['document' => $label, 'date' => Dates::date($date), 'days' => $days]),
        };
    }

    /**
     * One badge for each document expired (red) or expiring soon (amber) — a column for all of them, so the
     * urgent one is read at once rather than worked out from several date columns.
     *
     * @param  array<string, string>  $documents
     */
    public static function column(array $documents): TextColumn
    {
        return TextColumn::make('expiring_documents')
            ->label(__('Expiring Documents'))
            ->badge()
            ->state(fn (Model $record): array => array_map(
                self::describe(...),
                array_keys($due = self::due($record, $documents)),
                $due,
            ))
            ->color(fn (string $state, Model $record): string => self::expired($state, $record, $documents) ? 'danger' : 'warning')
            ->icon(fn (string $state, Model $record): string => self::expired($state, $record, $documents) ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-clock')
            ->listWithLineBreaks()
            ->placeholder('—');
    }

    /**
     * Whether the badge shown is of a document already expired.
     *
     * @param  array<string, string>  $documents
     */
    private static function expired(string $state, Model $record, array $documents): bool
    {
        foreach (self::due($record, $documents) as $label => $date) {
            if (self::describe($label, $date) === $state) {
                return $date->lessThan(today());
            }
        }

        return false;
    }

    /** @param  array<string, string>  $documents */
    public static function filter(array $documents): Filter
    {
        return Filter::make('expiring_documents')
            ->label(__('Has Expiring Documents'))
            ->query(fn (Builder $query): Builder => self::constrain($query, $documents));
    }

    /**
     * The menu's count of the records with a document expired or expiring soon — none: no badge.
     *
     * @param  array<string, string>  $documents
     */
    public static function badge(Builder $query, array $documents): ?string
    {
        $count = self::constrain($query, $documents)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function badgeTooltip(): string
    {
        return __('Documents expired or expiring within :days days', ['days' => self::WINDOW_DAYS]);
    }
}
