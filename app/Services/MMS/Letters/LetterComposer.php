<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterParty;
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
    private const BLOCKS = ['recipients', 'signature', 'stamp', 'minutes.attendees', 'minutes.qa', 'minutes.signatures'];

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
        $matter->loadMissing(['matterParties.party']);
        $rows = $matter->matterParties;
        $top = $rows->filter(fn (MatterParty $mp) => empty($mp->parent_id) && $mp->role === 'party');

        $candidates = [];
        foreach ($top as $mp) {
            $label = self::typeLabel($mp->type, $arabic);
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

    private static function typeLabel(?string $type, bool $arabic): ?string
    {
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
        $values['minutes.attendees'] = $this->attendeesHtml((array) ($minutes['attendees'] ?? []));
        $values['minutes.qa'] = $this->questionsHtml((array) ($minutes['items'] ?? []));
        $values[self::SIGNATURES] = $this->signaturesHtml();

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

        if (blank($value) && $type !== 'items') {
            return $type === 'url' ? ['' => '', '.url' => ''] : ['' => ''];
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
    private function attendeesHtml(array $attendees): string
    {
        $arabic = $this->isArabic();

        return collect($attendees)
            ->filter(fn ($a) => is_array($a) && ! empty($a['present']) && filled($a['name'] ?? null))
            ->groupBy(fn (array $a) => trim((string) ($a['capacity'] ?? '')))
            ->map(function ($group, string $capacity) use ($arabic): string {
                $lines = $group->map(function (array $a) use ($arabic): string {
                    $parts = [trim(trim((string) ($a['title'] ?? '')).' '.trim((string) $a['name']))];
                    if (filled($a['id_number'] ?? null)) {
                        $parts[] = ($arabic ? 'رقم الهوية: ' : 'ID No.: ').trim((string) $a['id_number']);
                    }
                    if (filled($a['phone'] ?? null)) {
                        $parts[] = ($arabic ? 'رقم الهاتف: ' : 'Phone: ').trim((string) $a['phone']);
                    }

                    return '<p>'.e(implode(' – ', $parts)).'</p>';
                })->implode('');

                return ($capacity !== '' ? '<p><strong>'.e($capacity).':</strong></p>' : '').$lines;
            })
            ->implode('');
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

        foreach ($blocks as $key => $value) {
            $pattern = '/<p[^>]*>\s*\{\{\s*'.preg_quote($key, '/').'\s*\}\}\s*<\/p>/iu';
            $html = preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\$'], $value), $html);
        }

        // What's left: text placeholders (escaped) and inline blocks.
        $text = array_diff_key($values, $blocks);
        $html = BulkMailPlaceholders::apply($html, $text, escape: true);

        return $this->physicalAlignment(BulkMailPlaceholders::apply($html, $blocks));
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
