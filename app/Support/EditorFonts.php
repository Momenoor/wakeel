<?php

namespace App\Support;

use App\Models\LetterFont;
use Illuminate\Support\Collection;

/**
 * The fonts in the rich text editor (App\Filament\Forms\Components\RichText):
 * its font menu — the standard font, every uploaded one (Communication →
 * Templates → Fonts) and the usual Word fonts — and the editor's page,
 * written in the letter's own fonts as the PDF writes it (LetterPdf): the
 * English font for Latin letters, the Arabic font (or the English one, when
 * it has the Arabic letters) for Arabic.
 */
class EditorFonts
{
    /** The app's own font, as the browser and Word name it. */
    public const STANDARD = 'Boutros MBC Dinkum';

    /** The family the letter's two fonts are joined under in the editor (the PDF and Word read it as the letter's own font). */
    public const LETTER = 'Wakeel Letter';

    public const LETTER_STACK = "'Wakeel Letter', 'Boutros MBC Dinkum', Tahoma, sans-serif";

    /** Word's fonts, which the server does not have: text set in one is written in the letter's font (LetterPdf, the live view). */
    public const IN_LETTER_FONT = ['Arial', 'Calibri', 'Cambria', 'Georgia', 'Simplified Arabic', 'Tahoma', 'Times New Roman', 'Traditional Arabic', 'Verdana', self::LETTER];

    /** Fonts every Word user has, offered too (the PDF uses them where it has them). */
    private const WORD_FONTS = [
        'Arial' => 'arial,helvetica,sans-serif',
        'Calibri' => 'calibri,sans-serif',
        'Cambria' => 'cambria,serif',
        'Courier New' => "'courier new',courier,monospace",
        'Georgia' => 'georgia,serif',
        'Simplified Arabic' => "'simplified arabic',serif",
        'Tahoma' => 'tahoma,sans-serif',
        'Times New Roman' => "'times new roman',times,serif",
        'Traditional Arabic' => "'traditional arabic',serif",
        'Verdana' => 'verdana,sans-serif',
    ];

    /**
     * @param  Collection<int, LetterFont>  $uploaded
     */
    private function __construct(
        private Collection $uploaded,
        private ?LetterFont $latin,
        private ?LetterFont $arabic,
    ) {}

    /**
     * @param  array{0?: int|string|null, 1?: int|string|null}|array<int, int|string|null>  $letterFonts  [English font id, Arabic font id]
     */
    public static function make(array $letterFonts = []): self
    {
        $uploaded = rescue(fn () => LetterFont::query()->orderBy('name')->get()
            ->filter(fn (LetterFont $font): bool => isset($font->pdfFiles()['R']))
            ->values(), collect(), report: false);

        $find = fn ($id): ?LetterFont => filled($id) ? $uploaded->firstWhere('id', (int) $id) : null;

        return new self($uploaded, $find($letterFonts[0] ?? null), $find($letterFonts[1] ?? null));
    }

    /**
     * TinyMCE's font menu: "Name=css family; …" — for a letter, first its own
     * font (as the page is written in it, the menu shows it by that name).
     */
    public function menu(bool $document = false): string
    {
        $entries = $document ? [__('Letter font') => self::LETTER_STACK] : [];
        $entries[self::STANDARD] = "'".self::STANDARD."'";

        foreach ($this->uploaded as $font) {
            $name = self::name($font);
            $entries[$name] = "'{$name}'";
        }

        $entries += self::WORD_FONTS;

        return collect($entries)->map(fn (string $css, string $name): string => "{$name}={$css}")->implode('; ');
    }

    /**
     * The page of a letter, filling the field: the letter's fonts and size, its paragraphs, lists, headings and
     * tables as the PDF draws them, placeholders and signature blocks marked.
     */
    public function documentCss(): string
    {
        $family = self::LETTER_STACK;

        return $this->faces()
            // The page fills the field, white to its edges.
            .' html { background: #fff; }'
            .' body { box-sizing: border-box; background: #fff; color: #111827; margin: 0; padding: 16px 24px;'
            .' font-family: '.$family.'; font-size: 12pt; line-height: 1.55; text-align: justify; }'
            .' p { margin: 0 0 6pt 0; }'
            .' ol, ul { margin: 0 0 6pt 0; padding-inline-start: 18pt; } li { margin-bottom: 3pt; }'
            .' h1 { font-size: 18pt; } h2 { font-size: 16pt; } h3 { font-size: 14pt; } h4 { font-size: 12pt; }'
            .' h1, h2, h3, h4 { margin: 0 0 6pt 0; color: #000; }'
            .' table { border-collapse: collapse; } td, th { padding: 4pt; }'
            .' table[border]:not([border="0"]) > * > tr > td, table[border]:not([border="0"]) > * > tr > th { border: 1px solid #9ca3af; }'
            .self::markedCss();
    }

    /**
     * The letter's fonts on a page outside the editor (the live view, the attendees' preview): every font's
     *
     * @font-face, and the selector written in the letter's own.
     */
    public function letterCss(string $selector): string
    {
        return $this->faces().' '.$selector.' { font-family: '.self::LETTER_STACK.'; }';
    }

    /**
     * Text in the font it was set in, where the screen has it (or it was uploaded); where not — Calibri on
     * a phone — in the letter's font, as the PDF falls back, not in whatever the browser picks.
     */
    public static function withLetterFallback(string $html): string
    {
        $generic = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'];

        return preg_replace_callback('/font-family:\s*([^;"]+)/i', function (array $m) use ($generic): string {
            $families = array_filter(array_map('trim', explode(',', $m[1])), fn (string $family): bool => $family !== '' && ! in_array(strtolower($family), $generic, true));

            return 'font-family: '.implode(', ', [...$families, self::LETTER_STACK]);
        }, $html) ?? $html;
    }

    /** An email: the mail's own plain look. */
    public function emailCss(): string
    {
        return $this->faces()
            .' body { margin: 12px 16px; font-family: Calibri, Arial, Tahoma, sans-serif; font-size: 11pt; line-height: 1.5; color: #111827; }'
            .' p { margin: 0 0 8pt 0; } img { vertical-align: middle; }'
            .' table { border-collapse: collapse; } td, th { padding: 4px 6px; }'
            .self::markedCss();
    }

    /** Placeholders (shown as one piece) and signature blocks (a labelled box). */
    private static function markedCss(): string
    {
        return ' span[data-mce-content] { background: #eef2ff; color: #3730a3; border-radius: 3px; padding: 0 2px; white-space: normal; unicode-bidi: isolate; direction: ltr; cursor: default; }'
            .' div[data-type="customBlock"] { box-sizing: border-box; margin: 6pt 0; padding: 10px 14px; border: 1px dashed #9ca3af; border-radius: 6px;'
            .' background: #f9fafb; color: #4b5563; font-size: 10pt; line-height: 1.4; cursor: default; user-select: none; text-align: start; }'
            .' div[data-type="customBlock"]::before { content: attr(data-label); }'
            .' div[data-type="customBlock"][data-mce-selected] { outline: 2px solid #3b82f6; }';
    }

    /**
     * @font-face for the standard font, every uploaded one under its own
     * name, and the letter's own pair joined under one family.
     */
    private function faces(): string
    {
        $standard = asset('fonts/BoutrosMBCDinkum-Medium.ttf');
        $css = "@font-face { font-family: '".self::STANDARD."'; font-weight: 100 900; font-display: swap; src: url('{$standard}') format('truetype'); }";

        foreach ($this->uploaded as $font) {
            $css .= ' '.InterfaceFont::faces($font, self::name($font));
        }

        // As the PDF: the Arabic in the Arabic font — or in the English one
        // when it has the letters — and the rest in the English font.
        $arabic = $this->arabic ?? ($this->latin?->coversArabic() ? $this->latin : null);

        if ($this->latin && $arabic && $arabic->is($this->latin)) {
            $css .= ' '.InterfaceFont::faces($this->latin, self::LETTER);
        } else {
            $css .= $this->latin ? ' '.InterfaceFont::faces($this->latin, self::LETTER, InterfaceFont::LATIN_RANGE) : '';
            $css .= $arabic ? ' '.InterfaceFont::faces($arabic, self::LETTER, InterfaceFont::ARABIC_RANGE) : '';
        }

        return $css;
    }

    /** A font's name as a CSS family and a menu entry. */
    private static function name(LetterFont $font): string
    {
        return trim(str_replace(["'", '"', '\\', '<', '>', ';', '=', ','], '', (string) $font->name));
    }
}
