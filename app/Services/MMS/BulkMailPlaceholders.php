<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\ExpertiseArea;
use App\Models\ExpertRegistration;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Type;
use App\Support\CompanyContact;
use App\Support\Dates;
use App\Support\Marks;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Collection;

/**
 * Fills {{placeholders}} in a bulk mail's subject and body.
 *
 * Three sources, later ones winning on the same key:
 *  1. the campaign's matter — {{matter.reference}}, {{matter.court}},
 *     {{matter.plaintiffs}}, … — only when the campaign has one; a general
 *     mailing (no matter) has none of these;
 *  2. the recipient's own columns from the imported Excel — any column
 *     besides name and email, e.g. {{amount}} or {{claim_number}};
 *  3. {{name}} and {{email}} of the recipient.
 *
 * Keys match loosely: {{ Matter.Court }}, {{matter.court}} and
 * {{matter court}} are the same placeholder. One that matches nothing is
 * left in the text as typed, so a typo shows up in the preview.
 */
class BulkMailPlaceholders
{
    /** Prefix of the hidden values {{each …}} repeats over, and what separates their items. */
    public const EACH = '__each.';

    public const EACH_CAPACITY = '__capacity.';

    public const EACH_SEPARATOR = "\u{1F}";

    /**
     * Every matter placeholder with a readable label, for the campaign form.
     *
     * @return array<string, string>
     */
    public static function matterCatalog(): array
    {
        return [
            'matter.reference' => __('Matter number/year'),
            'matter.number' => __('Matter number'),
            'matter.year' => __('Year'),
            'matter.court' => __('Court'),
            'matter.court_phone' => __('Court phone'),
            'matter.court_email' => __('Court email'),
            'matter.court_address' => __('Court address'),
            'matter.type' => __('Matter type'),
            'matter.level' => __('Level'),
            'matter.status' => __('Status'),
            'matter.difficulty' => __('Difficulty'),
            'matter.commissioning' => __('Commissioning'),
            'matter.collection_status' => __('Collection status'),
            'matter.distributed_at' => __('Distributed at'),
            'matter.received_at' => __('Received at'),
            'matter.next_session_date' => __('Next session date'),
            'matter.initial_report_at' => __('Initial report date'),
            'matter.final_report_at' => __('Final report date'),
            'matter.parent' => __('Parent matter'),
            'matter.plaintiffs' => __('Plaintiffs'),
            'matter.defendants' => __('Defendants'),
            'matter.implicate_litigants' => __('Implicate litigants'),
            'matter.parties' => __('All parties'),
            'matter.representatives' => __('Representatives'),
            'matter.plaintiffs.representatives' => __('Plaintiffs\' representatives'),
            'matter.defendants.representatives' => __('Defendants\' representatives'),
            'matter.experts' => __('Experts'),
            'matter.experts.registration' => __('Experts').' — '.__('registration number at the court'),
            'matter.expertise' => __('Expertise areas'),
            'matter.assistants' => __('Assistants'),
            'matter.parties.count' => __('All parties').' — '.__('how many'),
            'matter.plaintiffs.count' => __('Plaintiffs').' — '.__('how many'),
            'matter.defendants.count' => __('Defendants').' — '.__('how many'),
            'matter.plaintiff_capacity' => __('Plaintiff').' — '.__('Capacity'),
            'matter.defendant_capacity' => __('Defendant').' — '.__('Capacity'),
            'matter.implicate_capacity' => __('Implicate Litigant').' — '.__('Capacity'),
            'matter.experts.count' => __('Experts').' — '.__('how many'),
        ];
    }

    /**
     * The areas of expertise of these experts, each once, with commas between
     * ("المحاسبة, الهندسة"). An expert holds one, kept as a key on their
     * role; named in the interface's language, or in Arabic / English as asked.
     *
     * @param  iterable<MatterParty>  $experts
     */
    public static function expertise(iterable $experts, ?bool $arabic = null): string
    {
        return collect($experts)
            ->flatMap(function (MatterParty $mp): array {
                $role = (array) $mp->party?->role;
                // One object ({role, type, field}) — or, as older rows may hold, a list of them.
                $roles = array_key_exists('field', $role) || array_key_exists('role', $role) ? [$role] : $role;

                return collect($roles)->pluck('field')->flatten()->filter(fn ($key) => is_string($key) && $key !== '')->all();
            })
            ->unique()
            ->map(fn (string $key): string => (string) ExpertiseArea::labelFor($key, $arabic))
            ->filter()
            ->unique()
            ->implode(', ');
    }

    /**
     * The experts' registration numbers at a court (ExpertRegistration), in their order, commas between.
     *
     * @param  iterable<MatterParty>  $experts
     */
    public static function registrations(iterable $experts, ?int $courtId): string
    {
        $ids = collect($experts)->pluck('party_id')->filter()->unique()->values();

        if (! $courtId || $ids->isEmpty()) {
            return '';
        }

        $numbers = ExpertRegistration::query()->where('court_id', $courtId)->whereIn('party_id', $ids)->pluck('number', 'party_id');

        return $ids->map(fn ($id) => $numbers[$id] ?? null)->filter()->implode(', ');
    }

    /**
     * @return array<string, string>
     */
    public static function forMatter(?Matter $matter): array
    {
        if (! $matter) {
            return [];
        }

        $matter->loadMissing(['court', 'type', 'parent', 'matterParties.party', 'matterParties.representatives.party']);

        // Top-level rows have parent_id null OR 0 (older data uses 0) — the
        // same rule as Matter::mainParties(); representatives have a parent.
        $top = $matter->matterParties->filter(fn (MatterParty $mp) => empty($mp->parent_id));
        $parties = $top->where('role', 'party');
        $names = fn (Collection $rows): string => $rows
            ->map(fn (MatterParty $mp) => $mp->party?->name)
            ->filter()
            ->implode(', ');

        // A side's representatives (its lawyers) — not those who only attended for it.
        $representativesOf = fn (string $type): Collection => $parties->where('type', $type)
            ->flatMap(fn (MatterParty $mp) => $mp->representatives->filter(fn (MatterParty $rep) => $rep->role !== 'attendee'));

        $values = [
            'matter.reference' => $matter->reference,
            'matter.number' => (string) $matter->number,
            'matter.year' => (string) $matter->year,
            'matter.court' => (string) $matter->court?->name,
            'matter.court_phone' => (string) $matter->court?->phone,
            'matter.court_email' => (string) $matter->court?->email,
            'matter.court_address' => (string) $matter->court?->address,
            'matter.type' => (string) $matter->type?->name,
            'matter.level' => self::format($matter->level),
            'matter.status' => self::format($matter->status),
            'matter.difficulty' => self::format($matter->difficulty),
            'matter.commissioning' => self::format($matter->commissioning),
            'matter.collection_status' => self::format($matter->collection_status),
            'matter.distributed_at' => self::format($matter->distributed_at),
            'matter.received_at' => self::format($matter->received_at),
            'matter.next_session_date' => self::format($matter->next_session_date),
            'matter.initial_report_at' => self::format($matter->initial_report_at),
            'matter.final_report_at' => self::format($matter->final_report_at),
            'matter.parent' => (string) $matter->parent?->reference,
            'matter.plaintiffs' => $names($parties->where('type', 'plaintiff')),
            'matter.defendants' => $names($parties->where('type', 'defendant')),
            'matter.implicate_litigants' => $names($parties->where('type', 'implicate-litigant')),
            'matter.parties' => $names($parties),
            'matter.representatives' => $names($parties->flatMap(fn (MatterParty $mp) => $mp->representatives)),
            'matter.plaintiffs.representatives' => $names($representativesOf('plaintiff')),
            'matter.defendants.representatives' => $names($representativesOf('defendant')),
            'matter.experts' => $names($top->where('role', 'expert')->where('type', '!=', 'assistant')),
            // Their registration numbers at the matter's court, in their order (one query).
            'matter.experts.registration' => self::registrations($top->where('role', 'expert')->where('type', '!=', 'assistant'), $matter->court_id),
            // The experts' areas of expertise, each once, with commas between.
            'matter.expertise' => self::expertise($top->where('role', 'expert')->where('type', '!=', 'assistant')),
            // Assistants by their legal name (else their name).
            'matter.assistants' => $top->where('role', 'expert')->where('type', 'assistant')
                ->map(fn (MatterParty $mp) => $mp->party?->legalName())
                ->filter()
                ->implode(', '),
            // What each side is called in this matter's type (المتنازع, الطاعن …), else the usual name.
            'matter.plaintiff_capacity' => Type::sideLabel($matter->type, 'plaintiff'),
            'matter.defendant_capacity' => Type::sideLabel($matter->type, 'defendant'),
            'matter.implicate_capacity' => Type::sideLabel($matter->type, 'implicate-litigant'),
            // How many — for {{if matter.plaintiffs.count > 1 ? … : …}}.
            'matter.plaintiffs.count' => (string) $parties->where('type', 'plaintiff')->count(),
            'matter.defendants.count' => (string) $parties->where('type', 'defendant')->count(),
            'matter.implicate_litigants.count' => (string) $parties->where('type', 'implicate-litigant')->count(),
            'matter.plaintiffs.representatives.count' => (string) $representativesOf('plaintiff')->count(),
            'matter.defendants.representatives.count' => (string) $representativesOf('defendant')->count(),
            'matter.parties.count' => (string) $parties->count(),
            'matter.representatives.count' => (string) $parties->flatMap(fn (MatterParty $mp) => $mp->representatives)->count(),
            'matter.experts.count' => (string) $top->where('role', 'expert')->where('type', '!=', 'assistant')->count(),
            'matter.assistants.count' => (string) $top->where('role', 'expert')->where('type', 'assistant')->count(),
        ];

        // The names one by one, for {{each matter.plaintiffs}}…{{/each}}; {{item.capacity}} is the side's.
        $capacities = [
            'plaintiffs' => 'plaintiff', 'plaintiffs.representatives' => 'plaintiff',
            'defendants' => 'defendant', 'defendants.representatives' => 'defendant',
            'implicate_litigants' => 'implicate-litigant',
        ];
        foreach ([
            'plaintiffs' => $parties->where('type', 'plaintiff'),
            'defendants' => $parties->where('type', 'defendant'),
            'implicate_litigants' => $parties->where('type', 'implicate-litigant'),
            'experts' => $top->where('role', 'expert')->where('type', '!=', 'assistant'),
            'plaintiffs.representatives' => $representativesOf('plaintiff'),
            'defendants.representatives' => $representativesOf('defendant'),
        ] as $name => $rows) {
            $values[self::EACH.'matter.'.$name] = $rows
                ->map(fn (MatterParty $mp) => (string) $mp->party?->name)
                ->filter()
                ->implode(self::EACH_SEPARATOR);

            if (isset($capacities[$name])) {
                $values[self::EACH_CAPACITY.'matter.'.$name] = Type::sideLabel($matter->type, $capacities[$name]);
            }
        }

        // The matter type's own custom fields, e.g. {{matter.custom.Trustee}}.
        // A dropdown prints its option's label, not the key it is saved under.
        $definitions = $matter->type?->fieldDefinitions->keyBy('label') ?? collect();
        foreach ((array) ($matter->custom_fields ?? []) as $label => $value) {
            $values['matter.custom.'.$label] = self::format($definitions->get($label)?->displayValue($value) ?? $value);
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public static function for(BulkMailCampaign $campaign, BulkMailRecipient $recipient): array
    {
        $email = is_array($recipient->email) ? implode('; ', $recipient->email) : (string) $recipient->email;

        return [
            ...CompanyContact::values(),
            ...self::forMatter($campaign->matter),
            ...array_map(fn ($value) => self::format($value), $recipient->placeholders ?? []),
            'name' => (string) $recipient->name,
            'email' => $email,
        ];
    }

    /**
     * Replaces every {{key}} that has a value. $escape for HTML bodies, so
     * a name like "Smith & Sons" can't break the mail's markup. A part
     * written <<like this>> is kept only when its placeholders are filled
     * (see conditionals()).
     *
     * @param  array<string, string>  $values
     */
    public static function apply(string $text, array $values, bool $escape = false): string
    {
        $text = self::each($text, $values, $escape);
        $lookup = self::lookup($values);
        $text = self::choices($text, $values);
        $text = self::marks($text, $values);
        $text = self::conditionals($text, $values);

        // A switch that is on counts as filled for <<…>> parts, but prints
        // nothing.
        return str_replace(self::ON, '', preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', function (array $m) use ($lookup, $escape) {
            $key = self::normalize(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (! array_key_exists($key, $lookup)) {
                return $m[0];
            }

            return $escape ? e($lookup[$key]) : $lookup[$key];
        }, $text) ?? $text);
    }

    /**
     * Repeats a part once per item, written
     * {{each matter.plaintiffs}} … {{item.number}} {{item.name}} … {{/each}}
     * ({{item.count}} is how many there are). When the {{each}} and {{/each}}
     * are each alone in a paragraph, those paragraphs are dropped, so
     * the paragraphs between them repeat. With both tags inside one table
     * row, the whole row repeats. A list that isn't known here is left as typed.
     *
     * @param  array<string, string>  $values
     */
    public static function each(string $text, array $values, bool $escape = false): string
    {
        if (! preg_match('/\{\{\s*each\b/iu', $text)) {
            return $text;
        }

        $lookup = self::lookup($values);

        // The list's items, or null when it isn't known here.
        $items = function (string $tag) use ($lookup): ?array {
            $key = self::normalize(self::EACH.html_entity_decode(strip_tags($tag), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return array_key_exists($key, $lookup)
                ? array_values(array_filter(explode(self::EACH_SEPARATOR, $lookup[$key]), 'strlen'))
                : null;
        };

        $capacityOf = fn (string $tag): string => $lookup[self::normalize(self::EACH_CAPACITY.html_entity_decode(strip_tags($tag), ENT_QUOTES | ENT_HTML5, 'UTF-8'))] ?? '';

        $repeat = fn (string $part, array $list, string $capacity): string => collect($list)->map(fn (string $name, int $i) => preg_replace_callback(
            '/\{\{\s*item[.\s_-](name|number|count|capacity)\s*\}\}/iu',
            fn (array $t) => match (mb_strtolower($t[1])) {
                'name' => $escape ? e($name) : $name,
                'number' => (string) ($i + 1),
                'capacity' => $escape ? e($capacity) : $capacity,
                default => (string) count($list),
            },
            $part,
        ))->implode('');

        // A table row holding both tags is repeated whole, once per item.
        $text = preg_replace_callback(
            '/(<tr\b[^>]*>(?:(?!<\/tr>).)*?)\{\{\s*each\s+([^{}]+?)\s*\}\}((?:(?!<\/tr>).)*?)\{\{\s*\/each\s*\}\}((?:(?!<\/tr>).)*?<\/tr>)/isu',
            fn (array $m): string => ($list = $items($m[2])) === null ? $m[0] : $repeat($m[1].$m[3].$m[4], $list, $capacityOf($m[2])),
            $text,
        ) ?? $text;

        // Otherwise the part between the tags (their own paragraphs dropped).
        return preg_replace_callback(
            '/(?:<p[^>]*>\s*)?\{\{\s*each\s+([^{}]+?)\s*\}\}(?:\s*<\/p>)?(.*?)(?:<p[^>]*>\s*)?\{\{\s*\/each\s*\}\}(?:\s*<\/p>)?/isu',
            fn (array $m): string => ($list = $items($m[1])) === null ? $m[0] : $repeat($m[2], $list, $capacityOf($m[1])),
            $text,
        ) ?? $text;
    }

    /**
     * Choices written {{if CONDITION ? WHEN TRUE : WHEN FALSE}} — each side
     * may hold placeholders, or be empty. The condition compares a tag, or
     * the count of a tag's items, with a number:
     *
     *   {{if minutes.attendees.count > 1 ? الحاضرين : الحاضر}}
     *   {{if count(matter.plaintiffs) = 1 ? المدعي : المدعين}}
     *   {{if matter.experts.count >= 2 ? الخبراء : الخبير}}
     *
     * = (or ==), !=, >, <, >=, <=; a tag alone is true when not empty. = and !=
     * also compare with a word — {{if input.meeting_type = online ? … : …}}.
     * One whose tag isn't known here yet is left as it is, decided later.
     *
     * @param  array<string, string>  $values
     */
    public static function choices(string $text, array $values): string
    {
        if (! preg_match('/\{\{\s*if\b/iu', $text)) {
            return $text;
        }

        $lookup = self::lookup($values);

        return preg_replace_callback('/\{\{\s*if\s+(.+?)\s*[?؟]\s*(.*?)\s*:\s*(.*?)\s*\}\}/isu', function (array $m) use ($lookup): string {
            $true = self::decide($m[1], $lookup);

            return $true === null ? $m[0] : ($true ? $m[2] : $m[3]);
        }, $text) ?? $text;
    }

    /**
     * A tick or a dot, as a picture, where a condition holds — the same
     * condition as {{if …}}'s:
     *
     *   {{box minutes.attendees.claimant.count > 0}}   a check box, ticked when someone did
     *   {{radio input.meeting_type = online}}          a radio button, selected when it is
     *
     * (Text symbols print as empty boxes in the letters' font.) One whose
     * tag isn't known here yet is left as it is, decided later.
     *
     * @param  array<string, string>  $values
     */
    public static function marks(string $text, array $values): string
    {
        if (! preg_match('/\{\{\s*(?:box|radio)\b/iu', $text)) {
            return $text;
        }

        $lookup = self::lookup($values);

        return preg_replace_callback('/\{\{\s*(box|radio)\s+(.+?)\s*\}\}/isu', function (array $m) use ($lookup): string {
            $on = self::decide($m[2], $lookup);

            if ($on === null) {
                return $m[0];
            }

            return strtolower($m[1]) === 'box' ? Marks::box($on) : Marks::radio($on);
        }, $text) ?? $text;
    }

    /**
     * Whether a condition holds: a tag (true when not empty), or a tag or the
     * count of its items compared with a number — or, with = and !=, with
     * a word ("online", or "Court session" in quotes). Null when its tag
     * isn't known here yet.
     *
     * @param  array<string, string>  $lookup
     */
    private static function decide(string $condition, array $lookup): ?bool
    {
        // The editor writes > and < as &gt; &lt;.
        $condition = trim(html_entity_decode(strip_tags($condition), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! preg_match('/^(count\s*\(\s*(.+?)\s*\)|[^<>=!]+?)\s*(?:(==|=|!=|>=|<=|>|<)\s*(-?[\d.]+|"[^"]*"|\'[^\']*\'|\S+))?$/iu', $condition, $c)) {
            return null;
        }

        $counting = stripos($c[1], 'count') === 0 && isset($c[2]) && $c[2] !== '';
        $key = self::normalize($counting ? $c[2] : $c[1]);

        if (! array_key_exists($key, $lookup)) {
            return null;
        }

        $value = $counting ? self::countOf($lookup[$key]) : self::numberOrText($lookup[$key]);
        $operator = $c[3] ?? '';
        $against = trim($c[4] ?? '', '"\'');

        if ($operator === '') {
            return is_numeric($value) ? (float) $value != 0.0 : trim((string) $value) !== '';
        }

        // A word: equal or not, whatever its case.
        if (! is_numeric($against)) {
            $same = mb_strtolower(trim((string) $value)) === mb_strtolower($against);

            return match ($operator) {
                '=', '==' => $same,
                '!=' => ! $same,
                default => false,
            };
        }

        if (! is_numeric($value)) {
            return false;
        }

        $number = (float) $against;

        return match ($operator) {
            '=', '==' => (float) $value == $number,
            '!=' => (float) $value != $number,
            '>' => (float) $value > $number,
            '<' => (float) $value < $number,
            '>=' => (float) $value >= $number,
            '<=' => (float) $value <= $number,
            default => false,
        };
    }

    /**
     * How many items a tag holds: a number as itself; a list — its rows,
     * paragraphs or list items, else its parts between commas — counted.
     */
    public static function countOf(string $value): int
    {
        $plain = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($plain === '') {
            return 0;
        }

        if (is_numeric($plain)) {
            return (int) $plain;
        }

        foreach (['/<tr\b/i', '/<li\b/i', '/<p\b/i'] as $tag) {
            if (($n = preg_match_all($tag, $value)) > 0) {
                // A table's heading row is not an item.
                return $tag === '/<tr\b/i' && preg_match('/<th\b/i', $value) ? max(0, $n - 1) : $n;
            }
        }

        return count(array_filter(array_map('trim', preg_split('/[,،;\n]+/u', $plain) ?: []), fn (string $part): bool => $part !== ''));
    }

    private static function numberOrText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The value of an on/off field that is on: invisible, but not empty —
     * a part [[…{{input.KEY}}…]] shows when it's on, and goes when it's off.
     */
    public const ON = "\u{2063}";

    /**
     * Parts written <<…>>: kept — without the << >> — when every
     * placeholder in them has a value; left out whole when any is empty, a
     * paragraph (or list item) they made up taken out with them, so no gap
     * is left. A part with no placeholder is simply kept. A part whose
     * placeholders aren't known here yet — {{recipient.name}} before each
     * recipient's own email — is left as it is, to be decided when they are.
     *
     * [[…]] works the same. The editor keeps << >> as &lt;&lt; &gt;&gt;;
     * both are read.
     *
     * @param  array<string, string>  $values
     */
    public static function conditionals(string $text, array $values): string
    {
        if (! str_contains($text, '&lt;&lt;') && ! str_contains($text, '<<') && ! str_contains($text, '[[')) {
            return $text;
        }

        $lookup = self::lookup($values);
        $gone = "\u{E001}";

        $text = preg_replace_callback('/(?:&lt;&lt;|<<)(.*?)(?:&gt;&gt;|>>)|\[\[(.*?)\]\]/su', function (array $m) use ($lookup, $gone): string {
            $part = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
            preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $part, $found);
            $keys = array_map(fn (string $key) => self::normalize(html_entity_decode($key, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $found[1]);

            // Not all known yet: decided later.
            if (array_diff($keys, array_keys($lookup)) !== []) {
                return $m[0];
            }

            $filled = array_filter($keys, fn (string $key) => preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', html_entity_decode(strip_tags($lookup[$key]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '');

            return count($filled) === count($keys) ? $part : $gone;
        }, $text) ?? $text;

        // A paragraph or list item that was only the part left out goes too.
        $text = preg_replace('/<(p|li|h[1-6])\b[^>]*>(?:\s|&nbsp;|&#160;|<br\s*\/?>)*'.$gone.'(?:\s|&nbsp;|&#160;|<br\s*\/?>)*<\/\1>/u', '', $text) ?? $text;

        // Where parts went between two spaces, one space is left — not both
        // ("تقرر،  وأقفل"); written against a word or a full stop, none.
        $text = preg_replace('/[ \x{00A0}]+(?:'.$gone.'[ \x{00A0}]*)*'.$gone.'[ \x{00A0}]+/u', ' ', $text) ?? $text;
        $text = str_replace($gone, '', $text);

        // A part kept with its own spaces inside the brackets, next to the
        // spaces outside them: one.
        return preg_replace('/(?<=\S) {2,}(?=\S)/u', ' ', $text) ?? $text;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function lookup(array $values): array
    {
        $lookup = [];
        foreach ($values as $key => $value) {
            $lookup[self::normalize((string) $key)] = (string) $value;
        }

        return $lookup;
    }

    /**
     * The rich editor stores an inserted merge tag as
     * <span data-type="mergeTag" data-id="key">…</span>; it's read exactly
     * like a typed {{key}}.
     */
    public static function normalizeMergeTags(string $html): string
    {
        return preg_replace(
            '/<span[^>]*data-type="mergeTag"[^>]*data-id="([^"]+)"[^>]*>.*?<\/span>/su',
            '{{$1}}',
            $html,
        ) ?? $html;
    }

    /**
     * "Claim Amount", "claim_amount" and "claim-amount" are one key.
     */
    public static function normalize(string $key): string
    {
        return trim(preg_replace('/[\s_\-]+/u', '_', mb_strtolower(trim($key))), '_');
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof HasLabel => (string) $value->getLabel(),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof CarbonInterface => $value->format(Dates::dateFormat()),
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_array($value) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value)),
            default => (string) $value,
        };
    }
}
