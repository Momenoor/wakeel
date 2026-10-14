<?php

namespace App\Services\MMS\Letters;

use App\Enums\LetterTemplateCategories;
use App\Models\LetterTemplate;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;

/**
 * Makes a letter template from a Word file: its text and tables become the
 * template's HTML, and the {{placeholders}} typed in it stay as they are.
 * Every {{input.KEY}} found becomes a text field in the template's form, to
 * be refined (type, label, required) in the editor afterwards.
 */
class WordTemplateImporter
{
    /** @var list<string> placeholders the composer fills itself — no form field needed */
    private const BUILT_IN = ['matter', 'minutes', 'meeting', 'reference', 'date', 'subject', 'recipients', 'signature', 'stamp', 'company', 'if'];

    public function import(string $path, string $name, LetterTemplateCategories $category = LetterTemplateCategories::LETTER, string $locale = 'ar'): LetterTemplate
    {
        $body = $this->html($path);

        return LetterTemplate::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'subject' => $name,
            'body' => $body,
            'locale' => $locale,
            'category' => $category,
            'is_active' => false,
            'inputs' => $this->inputs($body),
        ]);
    }

    /**
     * The Word file's content as HTML (the part between <body> and </body>),
     * its tables evened out so the rich editor has nothing to pad.
     */
    public function html(string $path): string
    {
        $word = IOFactory::load($path, 'Word2007');
        $writer = IOFactory::createWriter($word, 'HTML');

        ob_start();
        $writer->save('php://output');
        $html = (string) ob_get_clean();

        if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $m)) {
            $html = $m[1];
        }

        return RaggedTables::even($this->mendPlaceholders(trim($html)));
    }

    /**
     * Word splits text into runs, so a {{placeholder}} can come out with tags
     * inside it ({{input.<span>date</span>}}): take them out again.
     */
    public function mendPlaceholders(string $html): string
    {
        return preg_replace_callback('/\{\{(.*?)\}\}/su', function (array $m): string {
            $key = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return '{{'.trim(preg_replace('/\s+/u', ' ', $key)).'}}';
        }, $html) ?? $html;
    }

    /** @return list<array{key: string, label: string, type: string, required: bool}> */
    public function inputs(string $html): array
    {
        preg_match_all('/\{\{\s*input\.([^}\s.?:]+)/u', $html, $m);

        return collect($m[1])->unique()->map(fn (string $key): array => [
            'key' => $key,
            'label' => Str::headline($key),
            'type' => 'text',
            'required' => false,
        ])->values()->all();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;

        for ($i = 2; LetterTemplate::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
