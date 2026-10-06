<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\CompanyContact;
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
            'matter.experts' => __('Experts'),
            'matter.assistants' => __('Assistants'),
        ];
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
            'matter.experts' => $names($top->where('role', 'expert')->where('type', '!=', 'assistant')),
            'matter.assistants' => $names($top->where('role', 'expert')->where('type', 'assistant')),
        ];

        // The matter type's own custom fields, e.g. {{matter.custom.Trustee}}.
        foreach ((array) ($matter->custom_fields ?? []) as $label => $value) {
            $values['matter.custom.'.$label] = self::format($value);
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
        $lookup = self::lookup($values);
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
            $value instanceof CarbonInterface => $value->format('d/m/Y'),
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_array($value) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value)),
            default => (string) $value,
        };
    }
}
