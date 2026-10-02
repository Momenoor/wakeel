<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Type;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\Letters\Blocks\SignatureBlock;
use App\Support\RichHtml;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Turns a template, a matter, the chosen recipients and the filled-in
 * inputs into the letter's HTML — the same {{placeholders}} as bulk mail
 * ({{matter.*}}), plus the letter's own:
 *
 *   {{reference}}, {{date}}, {{subject}}
 *   {{recipients}}           the addressee block, every chosen party
 *   {{input.KEY}}            what was typed in the template's form
 *   {{input.KEY.day}}        a date input's weekday (الأربعاء)
 *   {{input.KEY}} (items)    the ticked library items as a numbered list
 *   {{signature}}, {{stamp}} the letterhead's images
 *
 * Arabic templates (locale ar) get Arabic wording: "السادة/ … المحترمين",
 * weekdays, and صباحاً/مساءً for times.
 */
class LetterComposer
{
    /** Placeholders whose value is a block of HTML, not text. */
    private const BLOCKS = ['recipients', 'signature', 'stamp', 'minutes.attendees', 'minutes.qa', 'minutes.signatures', 'minutes.opening', 'minutes.closing'];

    /** {{minutes.signatures}}: in a letterhead text box (every page), drawn as its table. */
    public const SIGNATURES = 'minutes.signatures';

    /** Where a letter keeps the Teams meeting made when it was issued ({{meeting.link}}). */
    public const MEETING_LINK = '__meeting_link';

    /** …and when it is ({{meeting.date}}, {{meeting.day}}, {{meeting.time}}). */
    public const MEETING_START = '__meeting_start';

    /** A meeting's minutes ({{minutes.number}}, {{minutes.attendees}}, {{minutes.qa}}): {number, attendees, items}. */
    public const MINUTES = '__minutes';

    public function __construct(
        public LetterTemplate $template,
        public Matter $matter,
        /** @var array<string, mixed> */
        public array $inputs = [],
        /** @var list<array{name: string, role: ?string, emails: list<string>}> */
        public array $recipients = [],
        public ?string $reference = null,
        public ?CarbonInterface $date = null,
        public ?Letterhead $letterhead = null,
        // Printed under the addressees: "لعناية السيد/ … المحترم".
        public ?string $attention = null,
    ) {
        $this->date ??= now();
        $this->letterhead ??= $template->letterhead ?? Letterhead::default() ?? Letterhead::fallback();
    }

    public function isArabic(): bool
    {
        return $this->template->locale === 'ar';
    }

    /**
     * Everyone on the matter a letter can be addressed to: each party (with
     * its representatives, who go with it), and each representative on
     * their own ("وكيل المدعي"), with their emails. Keyed by the
     * matter_party row id.
     *
     * @return array<int, array{name: string, role: ?string, emails: list<string>, party_id: int|null, representatives: list<array{name: string, emails: list<string>, party_id: int|null}>, of: int|null}>
     */
    public static function candidates(Matter $matter, bool $arabic = true): array
    {
        $matter->loadMissing(['matterParties.party', 'type']);
        $rows = $matter->matterParties;
        $top = $rows->filter(fn (MatterParty $mp) => empty($mp->parent_id) && $mp->role === 'party');

        $candidates = [];
        foreach ($top as $mp) {
            $label = self::typeLabel($mp->type, $arabic, $matter->type);
            $representatives = $rows->filter(fn (MatterParty $rep) => (int) $rep->parent_id === (int) $mp->id);

            $candidates[$mp->id] = [
                ...self::candidate($mp, $label),
                'representatives' => $representatives->map(fn (MatterParty $rep) => Arr::except(self::candidate($rep, null), ['role', 'representatives', 'of']))->values()->all(),
            ];

            foreach ($representatives as $rep) {
                $candidates[$rep->id] = [
                    ...self::candidate($rep, $label ? ($arabic ? 'وكيل '.$label : $label."'s representative") : null),
                    // Whose representative: ticked with their party, they're not added twice.
                    'of' => $mp->id,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @return array{name: string, role: ?string, emails: list<string>, party_id: int|null, representatives: list<array<string, mixed>>}
     */
    private static function candidate(MatterParty $mp, ?string $role): array
    {
        $emails = $mp->party?->email ?? [];
        $phones = $mp->party?->phone ?? [];

        return [
            'name' => (string) $mp->party?->name,
            'role' => $role,
            'emails' => array_values(array_filter(is_array($emails) ? $emails : [$emails])),
            'phones' => array_values(array_filter(is_array($phones) ? $phones : [$phones], 'filled')),
            'party_id' => $mp->party_id,
            'representatives' => [],
            'of' => null,
        ];
    }

    /**
     * A side's name: as the matter's type calls it (المتنازع, الطاعن …),
     * otherwise the usual one.
     */
    public static function typeLabel(?string $type, bool $arabic, ?Type $matterType = null): ?string
    {
        if ($arabic && ($own = $matterType?->capacity($type))) {
            return $own;
        }

        return match ($type) {
            'plaintiff' => $arabic ? 'المدعي' : 'Plaintiff',
            'defendant' => $arabic ? 'المدعى عليه' : 'Defendant',
            'implicate-litigant' => $arabic ? 'الخصم المدخل' : 'Implicated litigant',
            default => $type,
        };
    }

    /**
     * Every placeholder's value — text, or HTML for the blocks.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $values = [
            ...BulkMailPlaceholders::forMatter($this->matter),
            'reference' => (string) $this->reference,
            'date' => $this->date->format('d/m/Y'),
            'subject' => '',
            'recipients' => $this->recipientsHtml(),
            'signature' => $this->imageHtml($this->letterhead?->file($this->letterhead?->signature_image), (float) ($this->letterhead?->signature_height ?: 45)),
            'stamp' => $this->imageHtml($this->letterhead?->file($this->letterhead?->stamp_image), (float) ($this->letterhead?->stamp_height ?: 40)),
        ];

        foreach ($this->template->inputs ?? [] as $input) {
            $key = 'input.'.($input['key'] ?? '');
            $value = $this->inputs[$input['key'] ?? ''] ?? null;

            foreach ($this->formatInput($input, $value) as $suffix => $formatted) {
                $values[$key.$suffix] = $formatted;
            }
        }

        // The Teams meeting made when the letter was issued: in any template.
        // None made: nothing there, not "{{meeting.link}}" (the issuer says so).
        if (filled($meeting = $this->inputs[self::MEETING_LINK] ?? null)) {
            ['' => $values['meeting.link'], '.url' => $values['meeting.link.url']] = $this->link((string) $meeting);
        } else {
            $values['meeting.link'] = $values['meeting.link.url'] = '';
        }

        // When it is, as the template's own date and time fields show them.
        $start = filled($this->inputs[self::MEETING_START] ?? null) ? Carbon::parse($this->inputs[self::MEETING_START]) : null;
        $values['meeting.date'] = $start ? $start->format('d/m/Y') : '';
        $values['meeting.day'] = $start ? $start->copy()->locale($this->isArabic() ? 'ar' : 'en')->translatedFormat('l') : '';
        $values['meeting.time'] = $start ? $this->time($start->format('H:i')) : '';

        // A meeting's minutes: its number, who attended, the questions and answers.
        $minutes = (array) ($this->inputs[self::MINUTES] ?? []);
        $values['minutes.number'] = (string) ($minutes['number'] ?? '');
        $values['minutes.attendees'] = self::attendeesHtml((array) ($minutes['attendees'] ?? []), (array) ($this->template->minutes_attendees ?? []), $this->isArabic());
        $values['minutes.qa'] = $this->questionsHtml((array) ($minutes['items'] ?? []));
        $values[self::SIGNATURES] = $this->signaturesHtml();
        $ended = filled($minutes['ended_at'] ?? null) ? Carbon::parse($minutes['ended_at']) : null;
        $values['minutes.end_time'] = $ended ? $this->time($ended->format('H:i')) : '';
        $values['minutes.end_date'] = $ended ? $ended->format('d/m/Y') : '';
        // Last: their wording may hold any of the above.
        $values['minutes.opening'] = $this->paragraphsHtml((string) ($minutes['opening'] ?? ''), $values);
        $values['minutes.closing'] = $this->paragraphsHtml((string) ($minutes['closing'] ?? ''), $values);

        $values['subject'] = BulkMailPlaceholders::apply((string) $this->template->subject, $values);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string> suffix => value ('' is the input itself)
     */
    private function formatInput(array $input, mixed $value): array
    {
        $type = $input['type'] ?? 'text';

        if ($type === 'toggle') {
            return ['' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? BulkMailPlaceholders::ON : ''];
        }

        // Empty, with every form it has (a date's .day too): a <<…>> part
        // holding any of them then goes, instead of waiting for a value.
        if (blank($value) && $type !== 'items') {
            return match ($type) {
                'url' => ['' => '', '.url' => ''],
                'date' => ['' => '', '.day' => ''],
                default => ['' => ''],
            };
        }

        return match ($type) {
            'date' => (function () use ($value) {
                $date = Carbon::parse($value)->locale($this->isArabic() ? 'ar' : 'en');

                return ['' => $date->format('d/m/Y'), '.day' => $date->translatedFormat('l')];
            })(),
            'time' => ['' => $this->time((string) $value)],
            // Line breaks kept (as HTML); a single line stays plain text.
            'textarea' => ['' => str_contains((string) $value, "\n") ? nl2br(e(trim((string) $value))) : (string) $value],
            'items' => ['' => $this->itemsHtml($input, (array) ($value ?? []))],
            'url' => $this->link(trim((string) $value)),
            default => ['' => (string) $value],
        };
    }

    /**
     * A link as a short clickable label: a Teams link is one 300-character
     * word that breaks anywhere — and in Arabic its pieces are reordered.
     * The address itself stays at {{input.KEY.url}}.
     *
     * @return array{'': string, '.url': string}
     */
    private function link(string $url): array
    {
        $meeting = (bool) preg_match('~(teams\.microsoft\.com|teams\.live\.com|zoom\.us|meet\.google\.com|webex\.com)~i', $url);
        $label = match (true) {
            $meeting && $this->isArabic() => 'انقر هنا للانضمام إلى الاجتماع',
            $meeting => 'Click here to join the meeting',
            $this->isArabic() => 'انقر هنا لفتح الرابط',
            default => 'Click here to open the link',
        };

        return ['' => '<a href="'.e($url).'">'.e($label).'</a>', '.url' => $url];
    }

    private function time(string $value): string
    {
        $time = Carbon::parse($value);

        if (! $this->isArabic()) {
            return $time->format('g:i A');
        }

        return $time->format('g:i').' '.($time->hour < 12 ? 'صباحاً' : 'مساءً');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int|string>  $chosen  item ids, or free text lines
     */
    private function itemsHtml(array $input, array $chosen): string
    {
        $ids = array_filter($chosen, 'is_numeric');
        $items = LetterItem::query()->whereIn('id', $ids)->get()->keyBy('id');

        $lines = collect($chosen)
            ->map(fn ($entry) => is_numeric($entry) ? $items->get($entry)?->text : $entry)
            ->filter(fn ($text) => filled($text))
            ->values();

        if ($lines->isEmpty()) {
            return '';
        }

        $heading = filled($input['heading'] ?? null)
            ? '<p><strong>'.e($input['heading']).'</strong></p>'
            : '';

        return $heading.'<ol>'.$lines->map(fn ($line) => '<li>'.e($line).'</li>')->implode('').'</ol>';
    }

    /**
     * The addressees. A party with representatives goes with them:
     *
     *  - by default on one line — "السادة/ منى أحمد (المدعي) ووكيله
     *    القانوني المحترمين" — their emails under it;
     *  - with name_representatives, the party on its line and each
     *    representative on one of their own: "ووكيله السادة/ … المحترمين".
     *    Parties sharing the same representatives are listed together, the
     *    representatives once after them: "ووكيلهم السادة/ …".
     */
    private function recipientsHtml(): string
    {
        $arabic = $this->isArabic();
        $recipients = array_values($this->recipients);
        $first = true;
        $done = [];
        $html = '';

        // Under each name, its emails then its phone numbers, left to right.
        $emails = fn (array $list, array $phones = []): string => collect([...$list, ...$phones])->filter(fn ($line) => filled($line))->map(fn ($line) => '<p class="recipient-email" dir="ltr">'.e((string) $line).'</p>')->implode('');
        $line = fn (string $text): string => '<p class="recipient"><strong>'.$text.'</strong></p>';
        $addressee = function (array $recipient, string $after = '') use ($arabic, &$first): string {
            $prefix = $arabic ? ($first ? 'السادة/ ' : 'والسادة/ ') : ($first ? 'Messrs. ' : 'And Messrs. ');
            $first = false;
            $role = filled($recipient['role'] ?? null) ? ' ('.e($recipient['role']).')' : '';

            return $prefix.e($recipient['name']).$role.$after.($arabic ? ' المحترمين' : '');
        };
        // The same representatives: the same people, in any order.
        $key = fn (array $recipient): string => collect($recipient['representatives'] ?? [])
            ->map(fn (array $rep) => filled($rep['party_id'] ?? null) ? 'p'.$rep['party_id'] : 'n'.$rep['name'])
            ->sort()->implode('|');

        foreach ($recipients as $i => $recipient) {
            if (isset($done[$i])) {
                continue;
            }

            $representatives = $recipient['representatives'] ?? [];

            if ($representatives === []) {
                $html .= $line($addressee($recipient)).$emails($recipient['emails'] ?? [], $recipient['phones'] ?? []);

                continue;
            }

            if (empty($recipient['name_representatives'])) {
                $html .= $line($addressee($recipient, $arabic ? ' ووكيله القانوني' : ' and their legal representative'))
                    .$emails(
                        [...$recipient['emails'] ?? [], ...collect($representatives)->flatMap(fn ($rep) => $rep['emails'] ?? [])->all()],
                        [...$recipient['phones'] ?? [], ...collect($representatives)->flatMap(fn ($rep) => $rep['phones'] ?? [])->all()],
                    );

                continue;
            }

            // Named: every party with these same representatives, then them.
            $group = collect($recipients)
                ->filter(fn (array $other, int $j) => $j >= $i && ! isset($done[$j]) && ! empty($other['name_representatives']) && $key($other) === $key($recipient));

            foreach ($group as $j => $party) {
                $done[$j] = true;
                $html .= $line($addressee($party)).$emails($party['emails'] ?? [], $party['phones'] ?? []);
            }

            $by = $arabic ? ($group->count() > 1 ? 'ووكيلهم' : 'ووكيله').' السادة/ ' : 'Represented by Messrs. ';

            foreach ($representatives as $rep) {
                $html .= $line($by.e($rep['name']).($arabic ? ' المحترمين' : '')).$emails($rep['emails'] ?? [], $rep['phones'] ?? []);
            }
        }

        return $html.$this->attentionHtml();
    }

    /**
     * Who attended, under their capacity ("وكيل المتنازعة:"), each on a line:
     * "الأستاذ/ … – رقم الهوية: … – رقم الهاتف: …".
     *
     * @param  list<array<string, mixed>>  $attendees
     */
    public static function attendeesHtml(array $attendees, array $settings = [], bool $arabic = true): string
    {
        $settings = self::attendeeSettings($settings, $arabic);
        $present = collect($attendees)
            ->filter(fn ($a) => is_array($a) && ! empty($a['present']) && filled($a['name'] ?? null))
            ->values();

        if ($present->isEmpty()) {
            return '';
        }

        if ($settings['layout'] === 'table') {
            return self::attendeesTable($present->all(), $settings['columns'], $arabic);
        }

        $line = fn (array $a, int $number) => self::attendeeText($settings['line'], $a, $number);

        if ($settings['layout'] === 'list') {
            return '<ol>'.$present->map(fn (array $a, int $i) => '<li>'.$line($a, $i + 1).'</li>')->implode('').'</ol>';
        }

        // Grouped: each capacity's heading, its attendees under it.
        $number = 0;

        return $present
            ->groupBy(fn (array $a) => trim((string) ($a['capacity'] ?? '')))
            ->map(function ($group, string $capacity) use ($settings, $line, &$number): string {
                $heading = $capacity !== '' ? self::attendeeText($settings['heading'], ['capacity' => $capacity], 0) : '';

                return ($heading !== '' ? '<p><strong>'.$heading.'</strong></p>' : '')
                    .$group->map(function (array $a) use ($line, &$number) {
                        return '<p>'.$line($a, ++$number).'</p>';
                    })->implode('');
            })
            ->implode('');
    }

    /** What {{minutes.attendees}} shows — the template's choices over these. */
    public static function attendeeSettings(array $settings, bool $arabic): array
    {
        $defaults = [
            'layout' => 'grouped',
            'line' => $arabic
                ? '{{attendee.title}} {{attendee.name}}<< – رقم الهوية: {{attendee.id_number}}>><< – رقم الهاتف: {{attendee.phone}}>>'
                : '{{attendee.title}} {{attendee.name}}<< – ID No.: {{attendee.id_number}}>><< – Phone: {{attendee.phone}}>>',
            'heading' => '{{attendee.capacity}}:',
            'columns' => ['number', 'name', 'capacity', 'id_number', 'signature'],
        ];

        $settings = array_filter($settings, fn ($value) => filled($value));

        return [
            ...$defaults,
            ...$settings,
            'layout' => in_array($settings['layout'] ?? null, ['grouped', 'list', 'table'], true) ? $settings['layout'] : 'grouped',
            'columns' => array_values(array_intersect(array_keys(self::attendeeColumns($arabic)), (array) ($settings['columns'] ?? $defaults['columns']))) ?: $defaults['columns'],
        ];
    }

    /**
     * The columns a table of attendees can have, as headed in the
     * template's language.
     *
     * @return array<string, string>
     */
    public static function attendeeColumns(bool $arabic): array
    {
        return $arabic
            ? ['number' => 'م', 'name' => 'الاسم', 'capacity' => 'الصفة', 'id_number' => 'رقم الهوية', 'phone' => 'رقم الهاتف', 'signature' => 'التوقيع']
            : ['number' => 'No.', 'name' => 'Name', 'capacity' => 'Capacity', 'id_number' => 'ID No.', 'phone' => 'Phone', 'signature' => 'Signature'];
    }

    /**
     * One attendee's line: its {{attendee.*}} filled, its <<…>> parts in only
     * when theirs are — escaped.
     */
    private static function attendeeText(string $format, array $a, int $number): string
    {
        $values = [
            'attendee.number' => $number > 0 ? (string) $number : '',
            'attendee.title' => trim((string) ($a['title'] ?? '')),
            'attendee.name' => trim((string) ($a['name'] ?? '')),
            'attendee.capacity' => trim((string) ($a['capacity'] ?? '')),
            'attendee.id_number' => trim((string) ($a['id_number'] ?? '')),
            'attendee.phone' => trim((string) ($a['phone'] ?? '')),
        ];

        // The ID and phone written left to right — in an Arabic line
        // "784-1990-1234567-1" otherwise comes out in reversed pieces.
        $ltr = [];
        foreach (['attendee.id_number', 'attendee.phone'] as $key) {
            if ($values[$key] !== '') {
                $token = "\u{E000}".count($ltr)."\u{E001}";
                $ltr[$token] = self::ltr($values[$key]);
                $values[$key] = $token;
            }
        }

        $text = strtr(BulkMailPlaceholders::apply(e($format), $values, escape: true), $ltr);

        return trim(preg_replace('/[ \x{00A0}]{2,}/u', ' ', $text) ?? $text);
    }

    /**
     * Numbers and codes kept left to right inside right-to-left text.
     */
    public static function ltr(string $text): string
    {
        return '<bdo dir="ltr">'.e($text).'</bdo>';
    }

    /**
     * @param  list<array<string, mixed>>  $attendees
     * @param  list<string>  $columns
     */
    private static function attendeesTable(array $attendees, array $columns, bool $arabic): string
    {
        $labels = self::attendeeColumns($arabic);
        $cell = 'border: 1px solid #444; padding: 4px 6px;';

        $rows = collect($attendees)->map(fn (array $a, int $i) => '<tr>'.collect($columns)->map(fn (string $column) => '<td style="'.$cell.($column === 'number' ? ' text-align: center;' : '').'">'.match ($column) {
            'number' => (string) ($i + 1),
            'name' => e(trim(trim((string) ($a['title'] ?? '')).' '.trim((string) $a['name']))),
            'signature' => '&#160;',
            'id_number', 'phone' => filled($a[$column] ?? null) ? self::ltr(trim((string) $a[$column])) : '',
            default => e(trim((string) ($a[$column] ?? ''))),
        }.'</td>')->implode('').'</tr>')->implode('');

        return '<table style="width: 100%; border-collapse: collapse;"><tr>'
            .collect($columns)->map(fn (string $column) => '<th style="'.$cell.' background: #f3f4f6;'.($column === 'signature' ? ' width: 25%;' : '').($column === 'number' ? ' width: 6%;' : '').'">'.e($labels[$column]).'</th>')->implode('')
            .'</tr>'.$rows.'</table>';
    }

    /**
     * The names of the minutes' attendees who were present, with their title
     * — who signs it.
     *
     * @return list<string>
     */
    public function signatureNames(): array
    {
        return collect((array) (((array) ($this->inputs[self::MINUTES] ?? []))['attendees'] ?? []))
            ->filter(fn ($a) => is_array($a) && ! empty($a['present']) && filled($a['name'] ?? null))
            ->map(fn (array $a) => trim(trim((string) ($a['title'] ?? '')).' '.trim((string) $a['name'])))
            ->values()
            ->all();
    }

    /**
     * The attendees' names, each over a line to sign on, three to a row —
     * for the foot of every page (a letterhead text box) or the end of the
     * minutes.
     */
    public function signaturesHtml(): string
    {
        $names = $this->signatureNames();
        if ($names === []) {
            return '';
        }

        $sign = $this->isArabic() ? 'التوقيع: ' : 'Signature: ';

        return '<table style="width: 100%; border-collapse: collapse;">'
            .collect($names)->chunk(3)->map(fn ($row) => '<tr>'.$row->map(fn (string $name) => '<td style="width: 33.3%; border: 0; padding: 1mm 2mm; text-align: center; vertical-align: top;">'
                .'<div><strong>'.e($name).'</strong></div>'
                .'<div style="margin-top: 5mm;">'.$sign.'....................</div>'
                .'</td>')->implode('').str_repeat('<td style="width: 33.3%; border: 0;"></td>', 3 - $row->count()).'</tr>')->implode('')
            .'</table>';
    }

    /**
     * The questions asked (س:) each with its answer (ج:), and the comments
     * made between them, in their order.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function questionsHtml(array $items): string
    {
        [$q, $a] = $this->isArabic() ? ['س:', 'ج:'] : ['Q:', 'A:'];
        $text = fn ($value): string => nl2br(e(trim((string) $value)), false);

        return collect($items)
            ->filter(fn ($item) => is_array($item) && filled($item['text'] ?? null))
            ->map(fn (array $item): string => ($item['type'] ?? 'question') === 'comment'
                ? '<p>'.$text($item['text']).'</p>'
                : '<p><strong>'.$q.'</strong> '.$text($item['text']).'</p><p><strong>'.$a.'</strong> '.$text($item['answer'] ?? '').'</p>')
            ->implode('');
    }

    /**
     * Text typed as plain lines (a minutes' opening or closing), each line
     * a paragraph — its placeholders filled, its <<…>> parts in only when
     * theirs are.
     *
     * @param  array<string, string>  $values
     */
    private function paragraphsHtml(string $text, array $values): string
    {
        if (blank($text)) {
            return '';
        }

        // In paragraphs first: a line that was only a part left out goes.
        $html = collect(preg_split('/\R/u', e(trim($text))) ?: [])
            ->map(fn (string $line) => '<p>'.trim($line).'</p>')
            ->implode('');

        return BulkMailPlaceholders::apply($html, array_map('strip_tags', $values), escape: true);
    }

    private function attentionHtml(): string
    {
        if (blank($this->attention)) {
            return '';
        }

        $line = $this->isArabic()
            ? 'لعناية السيد/ '.e(trim($this->attention)).' المحترم'
            : 'Attention: Mr. '.e(trim($this->attention));

        return '<p class="recipient"><strong>'.$line.'</strong></p>';
    }

    private function imageHtml(?string $file, float $heightMm): string
    {
        return $file ? '<img src="'.e($file).'" style="height: '.$heightMm.'mm;" />' : '';
    }

    /**
     * The letter's body: the template's rich text with every placeholder
     * filled. A block placeholder standing alone in its paragraph replaces
     * the whole paragraph, so a list never ends up inside a <p>.
     */
    public function bodyHtml(?string $body = null): string
    {
        $values = $this->values();
        $html = self::normalizeMergeTags($body ?? (string) $this->template->body);

        // Saved signature blocks, laid out on this letter's letterhead —
        // before the placeholders, which their lines may hold.
        $letterhead = $this->letterhead ?? Letterhead::default() ?? Letterhead::fallback();
        $pageWidth = $letterhead->orientation === 'landscape' ? Letterhead::PAGE_HEIGHT : Letterhead::PAGE_WIDTH;
        $html = SignatureLayouts::expand($html, $letterhead, $pageWidth - (float) $letterhead->margin_left - (float) $letterhead->margin_right);

        // Parts written <<…>>: in only when their placeholders are filled.
        $html = BulkMailPlaceholders::conditionals($html, $values);

        $blocks = array_filter($values, fn ($value, $key) => in_array($key, self::BLOCKS, true)
            || (str_starts_with($key, 'input.') && str_contains($value, '<ol>'))
            || (str_starts_with($key, 'input.') && str_contains($value, '<br'))
            || str_starts_with($value, '<a href='), ARRAY_FILTER_USE_BOTH);

        // The paragraph may wrap the placeholder in its formatting (a font
        // size, bold); the block takes the paragraph's place — and its size.
        $inline = '(?:span|strong|b|em|i|u)';
        foreach ($blocks as $key => $value) {
            $pattern = '/<p([^>]*)>((?:\s*<'.$inline.'\b[^>]*>)*)\s*\{\{\s*'.preg_quote($key, '/').'\s*\}\}\s*(?:<\/'.$inline.'>\s*)*<\/p>/iu';
            $html = preg_replace_callback($pattern, fn (array $m) => preg_match_all('/font-size:\s*([\d.]+pt)/i', $m[1].$m[2], $sizes)
                ? self::sized($value, end($sizes[1]))
                : $value, $html) ?? $html;
        }

        // What's left: text placeholders (escaped) and inline blocks.
        $text = array_diff_key($values, $blocks);
        $html = BulkMailPlaceholders::apply($html, $text, escape: true);

        return $this->physicalAlignment(BulkMailPlaceholders::apply($html, $blocks));
    }

    /**
     * A block's paragraphs, lists and list items at the size its
     * placeholder was written in — set on each, as neither mPDF nor Word
     * reliably passes a size down to a list.
     */
    private static function sized(string $html, string $size): string
    {
        // Inline only (a link): in a span of that size.
        if (! preg_match('/<(p|ol|ul|li|table|div)\b/i', $html)) {
            return '<span style="font-size: '.$size.';">'.$html.'</span>';
        }

        return preg_replace_callback('/<(p|ol|ul|li)\b([^>]*)>/i', function (array $m) use ($size) {
            if (preg_match('/font-size\s*:/i', $m[2])) {
                return $m[0];
            }

            return preg_match('/\sstyle="/i', $m[2])
                ? '<'.$m[1].preg_replace('/\sstyle="/i', ' style="font-size: '.$size.'; ', $m[2], 1).'>'
                : '<'.$m[1].$m[2].' style="font-size: '.$size.';">';
        }, $html) ?? $html;
    }

    /**
     * The editor aligns to the text's start or end; mPDF and Word know only
     * left and right — in an Arabic letter the start is the right.
     */
    private function physicalAlignment(string $html): string
    {
        return RichHtml::forOutput($html, $this->isArabic());
    }

    public function subject(): string
    {
        return $this->values()['subject'];
    }

    /**
     * The template's HTML as the letter reads it: an inserted merge tag as
     * a typed {{key}}, and a signature block as its lines and images.
     */
    public static function normalizeMergeTags(string $html): string
    {
        return BulkMailPlaceholders::normalizeMergeTags(SignatureBlock::expand($html));
    }

    /**
     * Every placeholder a template can use, with a label — for the
     * editor's merge-tag menu.
     *
     * @return array<string, string>
     */
    public static function catalog(?LetterTemplate $template = null, ?array $inputs = null): array
    {
        $catalog = [
            'reference' => __('Reference number'),
            'date' => __('Letter date'),
            'subject' => __('Subject'),
            'recipients' => __('Recipients block'),
            'signature' => __('Signature'),
            'stamp' => __('Stamp'),
            'meeting.link' => __('Teams meeting link (when made on issue)'),
            'meeting.link.url' => __('Teams meeting link').' — '.__('full address'),
            'minutes.number' => __('Minutes').' — '.__('number'),
            'minutes.attendees' => __('Minutes').' — '.__('attendees'),
            'minutes.qa' => __('Minutes').' — '.__('questions and answers'),
            'minutes.signatures' => __('Minutes').' — '.__('attendees\' signatures'),
            'minutes.opening' => __('Minutes').' — '.__('opening paragraph'),
            'minutes.closing' => __('Minutes').' — '.__('closing paragraph'),
            'minutes.end_time' => __('Minutes').' — '.__('time it ended'),
            'minutes.end_date' => __('Minutes').' — '.__('date it ended'),
            'meeting.date' => __('Meeting date'),
            'meeting.day' => __('Meeting date').' — '.__('weekday'),
            'meeting.time' => __('Meeting time'),
            ...BulkMailPlaceholders::matterCatalog(),
        ];

        foreach ($inputs ?? $template?->inputs ?? [] as $input) {
            if (blank($input['key'] ?? null)) {
                continue;
            }

            $catalog['input.'.$input['key']] = $input['label'] ?? $input['key'];

            if (($input['type'] ?? null) === 'date') {
                $catalog['input.'.$input['key'].'.day'] = ($input['label'] ?? $input['key']).' — '.__('weekday');
            }

            if (($input['type'] ?? null) === 'url') {
                $catalog['input.'.$input['key'].'.url'] = ($input['label'] ?? $input['key']).' — '.__('full address');
            }
        }

        return $catalog;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function elements(): Collection
    {
        return collect($this->letterhead?->elements ?? []);
    }
}
