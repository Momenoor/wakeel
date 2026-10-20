<?php

namespace App\Filament\Forms\Components;

use App\Models\SignatureLayout;
use App\Services\MMS\BulkMailService;
use App\Services\MMS\Letters\Blocks\SavedSignatureBlock;
use App\Services\MMS\Letters\Blocks\SignatureBlock;
use App\Support\EditorFonts;
use App\Support\EditorHtml;
use Closure;
use Visualbuilder\FilamentTinyEditor\TinyEditor;

/**
 * The system's rich text: TinyMCE (the visualbuilder/filament-tinyeditor
 * field, its TinyMCE self-hosted under public/vendor/tinymce — GPL, no Tiny
 * Cloud) with a Word-like toolbar.
 *
 *   RichText::make('body')->document()            // letters, templates, minutes: page breaks, Word's toolbar
 *   RichText::make('body')->email()               // emails and signatures: what an email can show
 *       ->placeholders(fn (Get $get) => [...])    // key => label; the { } menu, and {{ while typing
 *       ->signatureBlocks()                       // the signature blocks menu (letters)
 *       ->contentDirection(fn (Get $get) => 'rtl')
 *       ->letterFonts(fn (Get $get) => [$latinFontId, $arabicFontId])
 *
 * Placeholders are kept as typed {{key}} text (shown as one piece in the
 * editor), signature blocks as <div data-type="customBlock" …> — what the
 * letters, PDF, Word and email already read. The browser's HTML is cleaned
 * before it is kept (EditorHtml::clean), and text from the editor used
 * before is read as TinyMCE's (EditorHtml::fromTiptap).
 */
class RichText extends TinyEditor
{
    public const DOCUMENT = 'document';

    public const EMAIL = 'email';

    protected string $view = 'filament.forms.components.rich-text';

    protected string $preset = self::EMAIL;

    protected array|Closure|null $placeholders = null;

    protected bool|Closure $hasSignatureBlocks = false;

    protected string|Closure|null $contentDirection = null;

    protected array|Closure|null $letterFonts = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Pictures pasted or dropped in: kept with the public files, so an
        // email can show them and the PDF and Word read them from the disk.
        $this->fileAttachmentsDisk('public');
        $this->fileAttachmentsDirectory('editor-images');
        $this->fileAttachmentsVisibility('public');

        // Old text opens as TinyMCE's; what comes back is cleaned.
        $this->afterStateHydrated(function (RichText $component, ?string $state): void {
            if (EditorHtml::isTiptap($state)) {
                $component->state(EditorHtml::fromTiptap($state, $component->isRightToLeft()));
            }
        });

        $this->dehydrateStateUsing(fn (?string $state): ?string => EditorHtml::clean($state));
    }

    /** Letters, templates and minutes: Word's toolbar, page breaks, the letter's fonts and page. */
    public function document(): static
    {
        $this->preset = self::DOCUMENT;

        return $this;
    }

    /** Emails and signatures: what an email shows — no page breaks. */
    public function email(): static
    {
        $this->preset = self::EMAIL;

        return $this;
    }

    public function getPreset(): string
    {
        return $this->preset;
    }

    /**
     * @param  array<string, string>|Closure|null  $placeholders  key => label
     */
    public function placeholders(array|Closure|null $placeholders): static
    {
        $this->placeholders = $placeholders;

        return $this;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function getPlaceholders(): array
    {
        return collect((array) ($this->evaluate($this->placeholders) ?? []))
            ->map(fn ($label, $key): array => ['key' => (string) $key, 'label' => (string) $label])
            ->values()
            ->all();
    }

    public function hasPlaceholders(): bool
    {
        return $this->placeholders !== null;
    }

    public function signatureBlocks(bool|Closure $condition = true): static
    {
        $this->hasSignatureBlocks = $condition;

        return $this;
    }

    public function hasSignatureBlocks(): bool
    {
        return (bool) $this->evaluate($this->hasSignatureBlocks);
    }

    /** 'rtl', 'ltr', or 'auto' — by the text itself (Arabic in it: right to left). */
    public function contentDirection(string|Closure|null $direction): static
    {
        $this->contentDirection = $direction;

        return $this;
    }

    public function getContentDirection(): string
    {
        $direction = $this->evaluate($this->contentDirection);

        return in_array($direction, ['rtl', 'ltr'], true) ? $direction : 'auto';
    }

    /**
     * Whether the text is Arabic: by its direction, or — for 'auto' — by
     * what it says.
     */
    public function isRightToLeft(): bool
    {
        return match ($this->getContentDirection()) {
            'rtl' => true,
            'ltr' => false,
            default => BulkMailService::containsArabic((string) $this->getState()),
        };
    }

    /**
     * The letter's fonts: [English font id, Arabic font id] (LetterFont), either null.
     */
    public function letterFonts(array|Closure|null $fonts): static
    {
        $this->letterFonts = $fonts;

        return $this;
    }

    /**
     * The TinyMCE toolbar: Word's for a document, an email's subset otherwise —
     * the align buttons in the order they sit in the interface's direction.
     */
    public function getToolbar(): string
    {
        $align = app()->getLocale() === 'ar' ? 'alignright aligncenter alignleft alignjustify' : 'alignleft aligncenter alignright alignjustify';
        $own = trim(($this->hasPlaceholders() ? 'wkplaceholders ' : '').($this->hasSignatureBlocks() ? 'wkblocks' : ''));

        return implode(' | ', array_filter([
            'undo redo',
            'blocks fontfamily fontsize lineheight',
            'bold italic underline strikethrough superscript subscript forecolor backcolor removeformat',
            $align.' ltr rtl',
            'outdent indent bullist numlist',
            'table',
            $this->preset === self::DOCUMENT ? 'image link hr pagebreak blockquote' : 'image link hr blockquote',
            $own,
            'code fullscreen preview',
        ]));
    }

    public function getPlugins(): string
    {
        return 'wakeel advlist lists link image table code fullscreen preview directionality searchreplace charmap nonbreaking visualblocks autoresize'
            .($this->preset === self::DOCUMENT ? ' pagebreak' : '');
    }

    /**
     * Everything TinyMCE is started with (the browser adds the skin, the
     * placeholders and the upload handler: resources/js/rich-text-field.js).
     *
     * @return array<string, mixed>
     */
    public function getEditorConfig(): array
    {
        $document = $this->preset === self::DOCUMENT;
        $arabicInterface = app()->getLocale() === 'ar';
        $fonts = EditorFonts::make($this->evaluate($this->letterFonts) ?? []);

        return [
            'base_url' => asset('vendor/tinymce'),
            'suffix' => '.min',
            'license_key' => 'gpl',
            'promotion' => false,
            'branding' => false,
            ...($arabicInterface ? ['language' => 'ar', 'language_url' => asset('vendor/tinymce/langs/ar.js')] : []),
            // 'auto': the interface's direction, each paragraph then going its own text's way.
            'directionality' => $this->getContentDirection() === 'auto' ? ($arabicInterface ? 'rtl' : 'ltr') : $this->getContentDirection(),
            'plugins' => $this->getPlugins(),
            'toolbar' => $this->getToolbar(),
            'toolbar_mode' => 'wrap',
            'toolbar_sticky' => true,
            'toolbar_sticky_offset' => 64,
            'menubar' => false,
            'statusbar' => true,
            'elementpath' => false,
            'resize' => true,
            'min_height' => $document ? 520 : 280,
            'max_height' => 1400,
            'autoresize_bottom_margin' => 24,
            'contextmenu' => 'link image table',
            'browser_spellcheck' => true,

            // The output as Word and the PDF read it: compact, characters as
            // they are, absolute addresses (an email is read elsewhere).
            'entity_encoding' => 'raw',
            'indent' => false,
            'relative_urls' => false,
            'remove_script_host' => false,
            'convert_urls' => true,
            'indent_use_margin' => true,

            // Placeholders are one piece — a condition too ({{if count > 1 ? …}},
            // its > written &gt;), not one inside a tag (a link's address) — and
            // signature blocks keep their data.
            'noneditable_regexp' => '\{\{(?:[^{}<>&]|&(?:gt|lt|amp|quot|#39|nbsp);)+\}\}(?![^<]*>)',
            'extended_valid_elements' => 'div[data-type|data-id|data-config|data-label|contenteditable|class|style|dir|align|title|id]',
            'invalid_elements' => 'script,iframe,object,embed,form,input,button,select,textarea,style,link,meta,base,frame,frameset,applet',

            'block_formats' => implode('; ', [
                __('Paragraph').'=p',
                __('Heading 1').'=h1',
                __('Heading 2').'=h2',
                __('Heading 3').'=h3',
                __('Heading 4').'=h4',
                __('Preformatted').'=pre',
            ]),
            'font_family_formats' => $fonts->menu($document),
            'font_size_formats' => '8pt 9pt 10pt 10.5pt 11pt 12pt 13pt 14pt 15pt 16pt 18pt 20pt 22pt 24pt 26pt 28pt 32pt 36pt 48pt 72pt',
            'line_height_formats' => '1 1.15 1.25 1.5 1.75 2 2.5 3',
            'color_cols' => 8,

            // Tables as Word's: borders, widths in % of the page, columns
            // and rows dragged to size, each cell's own borders and shading.
            'table_default_attributes' => ['border' => '1'],
            'table_default_styles' => ['border-collapse' => 'collapse', 'width' => '100%'],
            'table_sizing_mode' => 'relative',
            'table_use_colgroups' => true,
            'table_resize_bars' => true,
            'table_column_resizing' => 'preservetable',
            'table_advtab' => true,
            'table_cell_advtab' => true,
            'table_row_advtab' => true,
            'table_appearance_options' => true,
            'table_style_by_css' => true,
            'table_border_widths' => [
                ['title' => '0', 'value' => '0px'], ['title' => '0.5 px', 'value' => '0.5px'], ['title' => '1 px', 'value' => '1px'],
                ['title' => '1.5 px', 'value' => '1.5px'], ['title' => '2 px', 'value' => '2px'], ['title' => '3 px', 'value' => '3px'],
            ],
            'table_toolbar' => 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol | tablemergecells tablesplitcells | tablecellprops tablerowprops | tablecellbackgroundcolor tablecellbordercolor tablecellborderwidth tablecellborderstyle tablecellvalign',
            'object_resizing' => 'img,table',

            'pagebreak_separator' => '<!-- pagebreak -->',
            'pagebreak_split_block' => true,

            'image_advtab' => true,
            'image_dimensions' => true,
            'image_description' => true,
            'automatic_uploads' => true,
            'paste_data_images' => true,
            'paste_merge_formats' => true,
            'paste_webkit_styles' => 'all',
            'images_file_types' => 'jpeg,jpg,png,gif,webp,bmp',

            'content_css' => 'default',
            'content_style' => ($document ? $fonts->documentCss() : $fonts->emailCss())
                .($this->getContentDirection() === 'auto' ? ' p, li, h1, h2, h3, h4, td, th, blockquote, pre { unicode-bidi: plaintext; text-align: start; }' : ''),

            'wakeel' => [
                'labels' => self::labels(),
                'blocks' => $this->hasSignatureBlocks() ? [
                    'saved' => SavedSignatureBlock::ID,
                    'signature' => SignatureBlock::ID,
                    'layouts' => SignatureLayout::query()->orderBy('name')->get(['id', 'name'])->map(fn (SignatureLayout $layout): array => ['id' => $layout->getKey(), 'name' => (string) $layout->name])->all(),
                ] : null,
            ],
        ];
    }

    /**
     * What may change while the form is open, read by the editor each time
     * its menu opens — no need to start it again.
     *
     * @return array{placeholders: list<array{key: string, label: string}>}
     */
    public function getLiveOptions(): array
    {
        return ['placeholders' => $this->getPlaceholders()];
    }

    /**
     * The editor's own words, in the interface's language.
     *
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return collect([
            'Placeholders', 'No placeholders', 'Edit placeholder', 'Placeholder', 'Signature blocks', 'Saved signature block', 'Signature and stamp',
            'Signature block', 'Signature', 'Stamp', 'Right', 'Centre', 'Left', 'Block', 'Edit', 'Remove', 'Name',
            'This name', 'No name', 'Position', 'Title above the name', 'The matter\'s expert', 'Type the name to sign with.',
            'The expert\'s name and title, the signature and the stamp, as they sign off the letter.',
            'Signature blocks are designed under Settings → Templates → Signature blocks.',
            'No saved signature blocks yet. They are designed under Settings → Templates → Signature blocks.',
        ])->mapWithKeys(fn (string $key): array => [$key => __($key)])->all();
    }
}
