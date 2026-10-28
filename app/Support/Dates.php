<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonInterface;
use Carbon\Translator;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * How a date and a time are shown, the same everywhere — as set for the whole system (Settings → General):
 * 22/10/2026 and 05:35 PM unless changed (AM/PM as written, not translated). Tables and pages take them as their
 * defaults (configureFilament()) — date and time pickers are Filament's own, as the browser writes them; what is
 * written by hand (emails, PDFs, notifications) uses the helpers (date(), time(), dateTime()) or the formats.
 */
final class Dates
{
    public const DATE_SETTING = 'date_format';

    public const TIME_SETTING = 'time_format';

    /** The formats offered, each shown as today's date (or time) written so. */
    public const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'm/d/Y'];

    public const TIME_FORMATS = ['h:i A', 'H:i'];

    public static function dateFormat(): string
    {
        return self::chosen(self::DATE_SETTING, self::DATE_FORMATS);
    }

    public static function timeFormat(): string
    {
        return self::chosen(self::TIME_SETTING, self::TIME_FORMATS);
    }

    /** Whether times are shown in 12 hours (AM/PM). */
    public static function twelveHours(): bool
    {
        return str_contains(self::timeFormat(), 'A');
    }

    public static function dateTimeFormat(): string
    {
        return self::dateFormat().' '.self::timeFormat();
    }

    public static function date(CarbonInterface|string|null $value): string
    {
        return self::format($value, self::dateFormat());
    }

    public static function time(CarbonInterface|string|null $value): string
    {
        return self::format($value, self::timeFormat());
    }

    public static function dateTime(CarbonInterface|string|null $value): string
    {
        return self::format($value, self::dateTimeFormat());
    }

    /**
     * The choices for the settings page: format => how it reads today.
     *
     * @param  list<string>  $formats
     * @return array<string, string>
     */
    public static function options(array $formats): array
    {
        $sample = Carbon::create(2026, 10, 22, 17, 35);

        return collect($formats)->mapWithKeys(fn (string $format): array => [$format => $sample->format($format)])->all();
    }

    /** Every table and page (infolist) of both panels. */
    public static function configureFilament(): void
    {
        // AM and PM written so in Arabic too (05:35 PM, not 05:35 م): what a date written by the panel in
        // Arabic, translated, would give otherwise. Day and month names stay translated.
        // On Carbon's own translator (the panel's, whose language follows the user's) and on Arabic's.
        foreach ([Carbon::getTranslator(), Translator::get('ar')] as $translator) {
            $translator->setMessages('ar', ['meridiem' => ['AM', 'PM']]);
        }

        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat(fn (): string => self::dateFormat())
            ->defaultDateTimeDisplayFormat(fn (): string => self::dateTimeFormat())
            ->defaultTimeDisplayFormat(fn (): string => self::timeFormat()));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateDisplayFormat(fn (): string => self::dateFormat())
            ->defaultDateTimeDisplayFormat(fn (): string => self::dateTimeFormat())
            ->defaultTimeDisplayFormat(fn (): string => self::timeFormat()));
    }

    /** @param  list<string>  $allowed */
    private static function chosen(string $key, array $allowed): string
    {
        try {
            $value = (string) Setting::get($key, $allowed[0]);
        } catch (Throwable) {
            return $allowed[0]; // Not installed yet.
        }

        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }

    private static function format(CarbonInterface|string|null $value, string $format): string
    {
        if (blank($value)) {
            return '';
        }

        return ($value instanceof CarbonInterface ? $value : Carbon::parse($value))->format($format);
    }
}
