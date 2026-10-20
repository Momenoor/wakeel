<?php

namespace App\Support;

use App\Services\MMS\BulkMailPlaceholders;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The rich text editors' HTML (TinyMCE, App\Filament\Forms\Components\RichText)
 * on its way in and out of the database.
 *
 *  - fromTiptap(): text written in the editor used before (Filament's, built
 *    on Tiptap) as TinyMCE keeps it — placeholders as typed {{key}}, the
 *    sides of the page instead of start/end, column widths and the font
 *    size, shading and border settings as the plain CSS TinyMCE edits. What
 *    the letter, PDF, Word and email make of it is unchanged: these are the
 *    very conversions the output already applied (RichHtml).
 *  - clean(): what the browser sends, before it is kept: no scripts, event
 *    handlers or script links (TinyMCE filters them too — this does not
 *    rely on it), and each signature block as the bare
 *    <div data-type="customBlock" data-id data-config> SignatureLayouts reads.
 */
class EditorHtml
{
    /** What only the old editor wrote. */
    private const TIPTAP_MARKS = '/data-type="mergeTag"|text-align:\s*(?:start|end)\b|data-colwidth=|data-font-size=|data-b[gwcs]=|data-[hv]align=|--color\s*:|<mark\b|oklch\(/i';

    public static function isTiptap(?string $html): bool
    {
        return filled($html) && (bool) preg_match(self::TIPTAP_MARKS, (string) $html);
    }

    /**
     * Old editor HTML as TinyMCE's; TinyMCE's (or anything else) as it is —
     * so it can run on any text, any number of times.
     */
    public static function fromTiptap(?string $html, bool $rtl): ?string
    {
        if (! self::isTiptap($html)) {
            return $html;
        }

        $html = BulkMailPlaceholders::normalizeMergeTags((string) $html);

        // The text colours (CSS variables) and the palette's oklch() as colours.
        $html = RichHtml::colours($html);
        $html = preg_replace('/(<span\b[^>]*?)\s(?:class="color"|data-color="[^"]*")/i', '$1', $html) ?? $html;
        $html = preg_replace('/(<span\b[^>]*?)\s(?:class="color"|data-color="[^"]*")/i', '$1', $html) ?? $html;

        // A highlight: a span with its background.
        $html = preg_replace_callback('/<mark\b([^>]*)>/i', function (array $m): string {
            $colour = preg_match('/background-color:\s*([^;"]+)/i', $m[1], $c) ? trim($c[1])
                : (preg_match('/data-color="([^"]+)"/i', $m[1], $c) ? $c[1] : '#fef08a');

            return '<span style="background-color: '.e($colour).'">';
        }, $html) ?? $html;
        $html = preg_replace('/<\/mark>/i', '</span>', $html) ?? $html;

        $html = TextDirection::physicalAlignment($html, $rtl);

        // Column widths: the width each cell already got on the way out.
        $html = RichHtml::columnWidths($html);

        // The extensions' own attributes: each wrote its CSS beside them.
        $html = preg_replace('/\s(?:data-colwidth|data-font-size|data-bg|data-bw|data-bc|data-bs|data-halign|data-valign)="[^"]*"/i', '', $html) ?? $html;

        return self::tableBorders($html);
    }

    /**
     * The old editor's tables had no border of their own: the PDF drew one
     * round every cell, Word none. As TinyMCE marks a table with borders —
     * border="1" — so the editor, the PDF and Word all draw them.
     */
    private static function tableBorders(string $html): string
    {
        return preg_replace_callback('/<table\b([^>]*)>/i', function (array $m): string {
            if (preg_match('/\sborder=/i', $m[1])) {
                return $m[0];
            }

            $attributes = $m[1];
            if (! preg_match('/\sstyle="/i', $attributes)) {
                $attributes .= ' style="border-collapse: collapse; width: 100%;"';
            } elseif (! preg_match('/border-collapse/i', $attributes)) {
                $attributes = preg_replace('/\sstyle="/i', ' style="border-collapse: collapse; ', $attributes, 1) ?? $attributes;
            }

            return '<table border="1"'.$attributes.'>';
        }, $html) ?? $html;
    }

    /** Elements never kept, with what is inside them. */
    private const DROPPED = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'option', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'title', 'head'];

    /** Anything that could run something. */
    private const DANGER = '/<(?:script|style|iframe|frame|frameset|object|embed|applet|form|input|button|select|textarea|option|link|meta|base|svg|math|template|noscript|title|head)\b|\son[a-z]+\s*=|(?:java|vb)script\s*:|expression\s*\(|\bdata\s*:(?!image\/(?:png|jpe?g|gif|webp|bmp);)|srcdoc|formaction/i';

    /**
     * The editor's HTML as it is kept. Ordinary text is left exactly as
     * TinyMCE wrote it; only what could run something sends it through the
     * parser, which keeps everything else.
     */
    public static function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (preg_match(self::DANGER, $html)) {
            $html = self::sanitize($html);
        }

        return self::bareBlocks($html);
    }

    /**
     * Each signature block as SignatureLayouts reads it: its id and settings,
     * nothing inside — whatever the editor showed in it, or added to it.
     */
    public static function bareBlocks(string $html): string
    {
        if (! str_contains($html, 'customBlock')) {
            return $html;
        }

        // An attribute's value however it is quoted (the parser writes JSON in single quotes).
        $attribute = fn (string $tag, string $name): ?string => preg_match('/\s'.$name.'=(?:"([^"]*)"|\'([^\']*)\')/', $tag, $m)
            ? html_entity_decode($m[2] ?? '' ?: $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;

        return preg_replace_callback('/<div\b([^>]*data-type=["\']customBlock["\'][^>]*)>.*?<\/div>/su', function (array $m) use ($attribute): string {
            $id = $attribute($m[1], 'data-id') ?? '';
            $config = $attribute($m[1], 'data-config') ?? '{}';

            return '<div data-type="customBlock" data-id="'.e($id).'" data-config="'.e($config).'"></div>';
        }, $html) ?? $html;
    }

    private static function sanitize(string $html): string
    {
        $dom = new DOMDocument;
        $errors = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body><div id="wk-root">'.$html.'</div></body></html>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }

        $root = $dom->getElementById('wk-root');
        if (! $root) {
            return strip_tags($html);
        }

        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('.//*', $root) ?: []) as $element) {
            if (! $element instanceof DOMElement || ! $element->parentNode) {
                continue;
            }

            if (in_array(strtolower($element->nodeName), self::DROPPED, true) || str_contains($element->nodeName, ':')) {
                $element->parentNode->removeChild($element);

                continue;
            }

            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = (string) $attribute->nodeValue;

                $unsafe = str_starts_with($name, 'on')
                    || in_array($name, ['srcdoc', 'formaction', 'xmlns'], true)
                    || (in_array($name, ['href', 'src', 'xlink:href', 'action', 'background', 'poster', 'cite'], true) && ! self::safeUrl($value, $name === 'src'))
                    || ($name === 'style' && preg_match('/expression\s*\(|(?:java|vb)script\s*:|url\s*\(\s*["\']?\s*(?!https?:|data:image\/)[a-z]+:/i', $value));

                if ($unsafe) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }
        }

        $out = '';
        foreach ($root->childNodes as $node) {
            $out .= self::outer($dom, $node);
        }

        return $out;
    }

    private static function outer(DOMDocument $dom, DOMNode $node): string
    {
        return (string) $dom->saveHTML($node);
    }

    private static function safeUrl(string $url, bool $image): bool
    {
        $url = trim(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        if ($url === '' || ! preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $scheme)) {
            // Relative, an anchor (#…) or a {{placeholder}}.
            return true;
        }

        $scheme = strtolower($scheme[1]);

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)
            || ($image && $scheme === 'data' && preg_match('/^data:image\/(?:png|jpe?g|gif|webp|bmp);/i', $url));
    }
}
