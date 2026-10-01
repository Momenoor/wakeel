<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\Letters\Blocks\SignatureBlock;
use App\Support\TextDirection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
    private const BLOCKS = ['recipients', 'signature', 'stamp'];

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
     * Everyone on the matter a letter can be addressed to: each party, and
     * each party's representatives ("وكيل المدعي"), with their emails.
     * Keyed by the matter_party row id.
     *
     * @return array<int, array{name: string, role: ?string, emails: list<string>, party_id: int|null}>
     */
    public static function candidates(Matter $matter, bool $arabic = true): array
    {
        $matter->loadMissing(['matterParties.party']);
        $rows = $matter->matterParties;
        $top = $rows->filter(fn (MatterParty $mp) => empty($mp->parent_id) && $mp->role === 'party');

        $candidates = [];
        foreach ($top as $mp) {
            $label = self::typeLabel($mp->type, $arabic);
            $candidates[$mp->id] = self::candidate($mp, $label);

            foreach ($rows->filter(fn (MatterParty $rep) => (int) $rep->parent_id === (int) $mp->id) as $rep) {
                $candidates[$rep->id] = self::candidate($rep, $label ? ($arabic ? 'وكيل '.$label : $label.' representative') : null);
            }
        }

        return $candidates;
    }

    /**
     * @return array{name: string, role: ?string, emails: list<string>, party_id: int|null}
     */
    private static function candidate(MatterParty $mp, ?string $role): array
    {
        $emails = $mp->party?->email ?? [];

        return [
            'name' => (string) $mp->party?->name,
            'role' => $role,
            'emails' => array_values(array_filter(is_array($emails) ? $emails : [$emails])),
            'party_id' => $mp->party_id,
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
            return ['' => ''];
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
            default => ['' => (string) $value],
        };
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

    private function recipientsHtml(): string
    {
        return collect($this->recipients)->map(function (array $recipient, int $i) {
            $prefix = $this->isArabic() ? ($i === 0 ? 'السادة/ ' : 'والسادة/ ') : ($i === 0 ? 'Messrs. ' : 'And Messrs. ');
            $role = filled($recipient['role'] ?? null) ? ' ('.e($recipient['role']).')' : '';
            $suffix = $this->isArabic() ? ' المحترمين' : '';

            $html = '<p class="recipient"><strong>'.$prefix.e($recipient['name']).$role.$suffix.'</strong></p>';

            foreach ($recipient['emails'] ?? [] as $email) {
                $html .= '<p class="recipient-email" dir="ltr">'.e($email).'</p>';
            }

            return $html;
        })->implode('').$this->attentionHtml();
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

        $blocks = array_filter($values, fn ($value, $key) => in_array($key, self::BLOCKS, true)
            || (str_starts_with($key, 'input.') && str_contains($value, '<ol>'))
            || (str_starts_with($key, 'input.') && str_contains($value, '<br')), ARRAY_FILTER_USE_BOTH);

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
        return TextDirection::physicalAlignment($html, $this->isArabic());
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
