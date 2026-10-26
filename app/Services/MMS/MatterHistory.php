<?php

namespace App\Services\MMS;

use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\Attachment;
use App\Models\CalendarEvent;
use App\Models\Court;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Models\MatterParty;
use App\Models\MatterRequest;
use App\Models\Note;
use App\Models\Party;
use App\Models\Type;
use App\Support\Dates;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * A matter's history: everything done to it and to what is on it — its data, its parties, requests, fees and
 * collections, notes, files, minutes, letters, emails and appointments — from the activity log. Each entry
 * knows its matter (activity_log.matter_id, written as it is logged), so it is found after what it is about
 * is deleted.
 */
final class MatterHistory
{
    /** What is on a matter, logged, as the history names it. */
    public const KINDS = [
        Matter::class => 'Matter',
        MatterParty::class => 'Party',
        MatterRequest::class => 'Request',
        Fee::class => 'Fee',
        Allocation::class => 'Collection',
        Note::class => 'Note',
        Attachment::class => 'File',
        MatterMinutes::class => 'Minutes',
        MatterLetter::class => 'Letter',
        MatterEmail::class => 'Email',
        CalendarEvent::class => 'Appointment',
    ];

    /** The fields shown as changed, by their labels — the rest (ids, files, kept data) are not news. */
    private const FIELDS = [
        Matter::class => [
            'number' => 'Number', 'year' => 'Year', 'court_id' => 'Court', 'type_id' => 'Type', 'level' => 'Level',
            'difficulty' => 'Difficulty', 'commissioning' => 'Commissioning', 'received_at' => 'Court Assigning Date',
            'distributed_at' => 'Assistant Assigning Date', 'next_session_date' => 'Next Session Date',
            'initial_report_at' => 'Initial Report Date', 'final_report_at' => 'Final Report Date',
            'final_report_memo_date' => 'Final Report Memo Date', 'review_count' => 'Review Count',
            'is_office_work' => 'Is Office Work', 'has_court_penalty' => 'Has Court Penalty',
            'has_substantive_changes' => 'Has Substantive Changes', 'is_committee' => 'Is Committee?',
        ],
        MatterParty::class => ['party_id' => 'Name', 'type' => 'Type', 'commission_percentage' => 'Commission %'],
        MatterRequest::class => ['status' => 'Status', 'comment' => 'Comment', 'approved_comment' => 'Reviewer Comment'],
        Fee::class => ['type' => 'Type', 'amount' => 'Amount', 'vat' => 'VAT', 'is_vat_included' => 'Including VAT', 'date' => 'Date', 'status' => 'Status', 'description' => 'Description'],
        Allocation::class => ['amount' => 'Amount', 'date' => 'Date', 'description' => 'Description'],
        Note::class => ['text' => 'Note'],
        Attachment::class => ['name' => 'Name', 'category' => 'Category'],
        MatterMinutes::class => ['number' => 'Number', 'meeting_at' => 'Meeting date', 'status' => 'Status'],
        MatterLetter::class => ['subject' => 'Subject', 'reference' => 'Reference', 'status' => 'Status'],
        MatterEmail::class => ['subject' => 'Subject', 'matter_id' => 'Matter'],
        CalendarEvent::class => ['title' => 'Title', 'start_datetime' => 'Date', 'location' => 'Location'],
    ];

    /** @var array<string, string|null> names read for this request */
    private static array $names = [];

    /** The matter a logged model is on. */
    public static function matterIdOf(?Model $subject): ?int
    {
        if ($subject instanceof Matter) {
            return (int) $subject->getKey();
        }

        if ($subject && isset(self::KINDS[$subject::class])) {
            $id = $subject->getAttribute('matter_id') ?? $subject->getOriginal('matter_id');

            return filled($id) ? (int) $id : null;
        }

        return null;
    }

    public static function kind(?string $class): string
    {
        return __(self::KINDS[$class] ?? 'Record');
    }

    /**
     * One entry, in words: what was done, and each field that changed.
     *
     * @return array{title: string, changes: list<string>}
     */
    public static function describe(Activity $activity): array
    {
        $class = (string) $activity->subject_type;
        $changes = $activity->attribute_changes ?? $activity->properties ?? collect();
        $changes = $changes instanceof Collection ? $changes->all() : (array) $changes;
        $new = (array) ($changes['attributes'] ?? []);
        $old = (array) ($changes['old'] ?? []);
        // What it is: as logged, else as it is now.
        $values = [...($activity->subject?->getAttributes() ?? []), ...$old, ...$new];
        $what = self::what($class, $values);
        $event = (string) $activity->event;

        $title = match (true) {
            $event === 'created' => self::created($class, $values, $what),
            $event === 'deleted' => __(':kind deleted: :what', ['kind' => self::kind($class), 'what' => $what]),
            $event === 'restored' => __(':kind restored: :what', ['kind' => self::kind($class), 'what' => $what]),
            default => self::updated($class, $old, $new, $values, $what),
        };

        $fields = $event === 'updated' ? self::FIELDS[$class] ?? [] : [];
        $lines = [];
        foreach ($fields as $field => $label) {
            if (! array_key_exists($field, $new) || self::same($old[$field] ?? null, $new[$field])) {
                continue;
            }
            $lines[] = __(':field: from :old to :new', [
                'field' => __($label),
                'old' => self::value($class, $field, $old[$field] ?? null),
                'new' => self::value($class, $field, $new[$field]),
            ]);
        }

        return ['title' => $title, 'changes' => $lines];
    }

    /** @param  array<string, mixed>  $values */
    private static function created(string $class, array $values, string $what): string
    {
        return match ($class) {
            Matter::class => __('Matter created'),
            Fee::class => __('Fee added: :what', ['what' => $what]),
            Allocation::class => __('Collected: :what', ['what' => $what]),
            MatterRequest::class => __('Request submitted: :what', ['what' => $what]),
            MatterMinutes::class => __('Minutes created: :what', ['what' => $what]),
            MatterLetter::class => __('Letter issued: :what', ['what' => $what]),
            MatterEmail::class => ($values['direction'] ?? null) === MatterEmail::SENT
                ? __('Email sent: :what', ['what' => $what])
                : __('Email received: :what', ['what' => $what]),
            default => __(':kind added: :what', ['kind' => self::kind($class), 'what' => $what]),
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $values
     */
    private static function updated(string $class, array $old, array $new, array $values, string $what): string
    {
        $became = fn (string $field): mixed => array_key_exists($field, $new) && ! self::same($old[$field] ?? null, $new[$field]) ? $new[$field] : null;

        return match (true) {
            $class === MatterMinutes::class && $became('status') === MatterMinutes::FINAL => __('Minutes finalised: :what', ['what' => $what]),
            $class === MatterLetter::class && $became('status') === 'sent' => __('Letter sent: :what', ['what' => $what]),
            $class === MatterEmail::class && $became('matter_id') !== null => __('Email filed on this matter: :what', ['what' => $what]),
            $class === MatterRequest::class && $became('status') === RequestStatus::PENDING->value => __('Request resubmitted: :what', ['what' => $what]),
            $class === MatterRequest::class && $became('status') !== null => __('Request :status: :what', [
                'status' => RequestStatus::tryFrom((string) $became('status'))?->getLabel() ?? (string) $became('status'),
                'what' => $what,
            ]),
            $class === Matter::class => __('Matter changed'),
            default => __(':kind changed: :what', ['kind' => self::kind($class), 'what' => $what]),
        };
    }

    /**
     * What an entry is about, in a few words: the fee's kind and amount, the minutes' number …
     *
     * @param  array<string, mixed>  $values
     */
    private static function what(string $class, array $values): string
    {
        $value = fn (string $field): string => self::value($class, $field, $values[$field] ?? null);

        return trim(match ($class) {
            Matter::class => trim(($values['year'] ?? '').'/'.($values['number'] ?? ''), '/'),
            Fee::class => $value('type').' — '.$value('amount'),
            Allocation::class => $value('amount').(filled($values['description'] ?? null) ? ' — '.$values['description'] : ''),
            MatterRequest::class => $value('type'),
            Note::class => Str::limit(strip_tags((string) ($values['text'] ?? '')), 80),
            Attachment::class => (string) ($values['name'] ?? ''),
            MatterParty::class => $value('party_id').(filled($values['type'] ?? null) ? ' ('.$value('type').')' : ''),
            MatterMinutes::class => __('Minutes no. :number', ['number' => $values['number'] ?? '—']),
            MatterLetter::class => (string) (($values['subject'] ?? null) ?: ($values['reference'] ?? '')),
            MatterEmail::class => (string) ($values['subject'] ?? ''),
            CalendarEvent::class => (string) ($values['title'] ?? ''),
            default => '',
        }, ' —') ?: '—';
    }

    /** A logged value as it reads: an option's label, a date, a party's name, an amount. */
    private static function value(string $class, string $field, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }

        $named = match ($field) {
            'court_id' => [Court::class, 'name'],
            'type_id' => [Type::class, 'name'],
            'party_id' => [Party::class, 'name'],
            'matter_id' => [Matter::class, null],
            default => null,
        };
        if ($named) {
            return self::$names[$field.':'.$raw] ??= $named[1] === null
                ? (($m = Matter::withTrashed()->find($raw)) ? $m->year.'/'.$m->number : '#'.$raw)
                : ((string) ($named[0]::query()->whereKey($raw)->value($named[1]) ?? '#'.$raw));
        }

        if (in_array($field, ['amount', 'vat', 'commission_percentage'], true) && is_numeric($raw)) {
            return number_format((float) $raw, 2);
        }

        // A date and time as logged — in UTC, written with its zone (the model's cast would drop the zone).
        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}T[\d:.]+(Z|[+-]\d{2}:?\d{2})$/', $raw)) {
            $raw = Carbon::parse($raw)->setTimezone((string) config('app.timezone'));
        }

        // As the model reads it: an option, a date, yes or no.
        try {
            $value = (new $class)->forceFill([$field => $raw])->getAttribute($field);
        } catch (\Throwable) {
            $value = $raw;
        }

        return match (true) {
            $value instanceof BackedEnum => method_exists($value, 'getLabel') ? (string) $value->getLabel() : (string) $value->value,
            $value instanceof CarbonInterface => $value->format($value->format('H:i') === '00:00' ? Dates::dateFormat() : Dates::dateTimeFormat()),
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_array($value) => implode('، ', array_map('strval', array_filter($value, 'is_scalar'))),
            default => Str::limit((string) $value, 120),
        };
    }

    private static function same(mixed $a, mixed $b): bool
    {
        // Nothing, and no / 0 / empty: the same (a new field given its default is not news).
        $empty = fn (mixed $v): bool => $v === null || $v === false || $v === 0 || $v === '0' || $v === '' || $v === [];
        if ($empty($a) && $empty($b)) {
            return true;
        }

        return (is_numeric($a) && is_numeric($b)) ? (float) $a === (float) $b : (string) json_encode($a) === (string) json_encode($b);
    }
}
