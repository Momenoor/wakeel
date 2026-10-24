// The PDF's own text, for changing it: each line found on a page (pdf.js's pieces of text joined where they
// sit on one baseline), the font it is set in, and its colours as drawn. A line is changed by covering it in
// its background colour and writing the new words over it, in that font where the PDF carries its letters
// (the font embedded in the file, loaded by pdf.js) and the nearest common one where it does not.
import { Util } from 'pdfjs-dist';

// Pieces this far apart (in font sizes) are still one line; further, two.
const GAP = 1.2;

// Names in PDFs ("ABCDEF+TimesNewRomanPS-BoldMT") and the font the browser knows them by.
const KNOWN = [
    [/arial|helvetica|liberation ?sans|arimo/i, 'Arial, Helvetica'],
    [/times|liberation ?serif|tinos/i, '"Times New Roman", Times'],
    [/courier|liberation ?mono|cousine/i, '"Courier New", Courier'],
    [/calibri|carlito/i, 'Calibri, Carlito'],
    [/cambria|caladea/i, 'Cambria, Caladea'],
    [/tahoma/i, 'Tahoma'],
    [/verdana/i, 'Verdana'],
    [/georgia/i, 'Georgia'],
    [/segoe/i, '"Segoe UI"'],
    [/simplified ?arabic/i, '"Simplified Arabic"'],
    [/traditional ?arabic/i, '"Traditional Arabic"'],
    [/sakkal/i, '"Sakkal Majalla"'],
    [/dubai/i, 'Dubai'],
    [/noto ?(naskh|sans) ?arabic/i, '"Noto Naskh Arabic", "Noto Sans Arabic"'],
    [/cairo/i, 'Cairo'],
    [/amiri/i, 'Amiri'],
];

/**
 * Every line of text on a page, in its display space (points, as items are kept): its box, words, size,
 * baseline, direction and pdf.js's font id. Upright text only — turned text is left alone.
 */
export async function textLines(page, viewport) {
    const content = await page.getTextContent();
    const pieces = [];

    for (const item of content.items) {
        if (! item.str?.trim()) {
            continue;
        }

        const [a, b, c, d, e, f] = Util.transform(viewport.transform, item.transform);

        // Turned or slanted on the screen: not ours to change.
        if (Math.abs(b) > 0.01 || Math.abs(c) > 0.01 || a <= 0) {
            continue;
        }

        // At scale 1, upright: its width is already in the page's points.
        pieces.push({ str: item.str, x: e, w: item.width, baseline: f, size: Math.hypot(c, d), dir: item.dir, font: item.fontName, ascent: content.styles[item.fontName]?.ascent || 0.8 });
    }

    pieces.sort((one, two) => one.baseline - two.baseline || one.x - two.x);

    const lines = [];

    for (const piece of pieces) {
        const line = lines.findLast((candidate) => Math.abs(candidate.baseline - piece.baseline) < piece.size * 0.3
            && Math.abs(candidate.size - piece.size) < piece.size * 0.15
            && piece.x - (candidate.x + candidate.w) < piece.size * GAP
            && candidate.x - (piece.x + piece.w) < piece.size * GAP);

        if (line) {
            line.pieces.push(piece);
            const right = Math.max(line.x + line.w, piece.x + piece.w);
            line.x = Math.min(line.x, piece.x);
            line.w = right - line.x;
        } else {
            lines.push({ pieces: [piece], x: piece.x, w: piece.w, baseline: piece.baseline, size: piece.size, font: piece.font, ascent: piece.ascent });
        }
    }

    return lines.map((line, index) => {
        const rtl = line.pieces.some((piece) => piece.dir === 'rtl');
        // Read in the order the words are written: right to left for Arabic.
        const ordered = [...line.pieces].sort((one, two) => (rtl ? two.x - one.x : one.x - two.x));
        const str = ordered.reduce((text, piece, at) => {
            const previous = ordered[at - 1];
            const gap = previous ? (rtl ? previous.x - (piece.x + piece.w) : piece.x - (previous.x + previous.w)) : 0;
            const space = previous && gap > piece.size * 0.15 && ! /\s$/.test(text) && ! /^\s/.test(piece.str);

            return text + (space ? ' ' : '') + piece.str;
        }, '').trim()
            // Arabic as a PDF often keeps it — each letter in its joined shape (ﻟﻤﺮﺟﻊ) — back to the letters
            // typed (لمرجع), which the browser joins again; anything else as it is.
            .replace(/[ﭐ-﷿ﹰ-﻿]+/g, (shapes) => shapes.normalize('NFKC'));
        const top = line.baseline - line.size * line.ascent;

        return { id: index, str, x: line.x, y: top, w: line.w, h: line.size * 1.15, size: line.size, baseline: line.baseline, rtl, font: line.font };
    });
}

/**
 * The font a line is set in: the CSS to draw it with — the PDF's own (embedded, loaded by pdf.js under its id)
 * first, then the common font it names, then its kind — whether bold or italic, and a name to show.
 */
export function fontOf(page, fontId) {
    const font = fontId && page.commonObjs.has(fontId) ? page.commonObjs.get(fontId) : null;
    const name = String(font?.name || '').replace(/^[A-Z]{6}\+/, '');
    const bold = Boolean(font?.bold || font?.black || /bold|black|heavy|semibold|demi/i.test(name));
    const italic = Boolean(font?.italic || /italic|oblique/i.test(name));
    const known = KNOWN.find(([pattern]) => pattern.test(name))?.[1];
    const kind = /mono|courier/i.test(name) ? 'monospace' : (/times|serif|roman|georgia|cambria|naskh|amiri|traditional/i.test(name) && ! /sans/i.test(name) ? 'serif' : 'sans-serif');
    const families = [font && ! font.missingFile && font.loadedName ? `"${font.loadedName}"` : null, known, kind].filter(Boolean).join(', ');
    const label = name.replace(/[-,]?(PSMT|MT|PS)$/i, '').replace(/[-,]/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').trim();

    return { family: families, style: `${italic ? 'italic ' : ''}${bold ? 'bold' : ''}`.trim(), label: label || kind };
}

/**
 * The colours a line is drawn in, read off the page as shown (its canvas): the background (the colour most
 * of its box is) and the text (the commonest of those clearly not the background). Black on white when the
 * page is not drawn yet.
 */
export function colorsOf(canvas, pageWidth, box) {
    const fallback = { text: '#000000', background: '#ffffff' };

    if (! canvas?.width) {
        return fallback;
    }

    const scale = canvas.width / pageWidth;
    const x = Math.max(0, Math.floor(box.x * scale));
    const y = Math.max(0, Math.floor(box.y * scale));
    const w = Math.min(canvas.width - x, Math.ceil(box.w * scale));
    const h = Math.min(canvas.height - y, Math.ceil(box.h * scale));

    if (w < 1 || h < 1) {
        return fallback;
    }

    let pixels;

    try {
        pixels = canvas.getContext('2d', { willReadFrequently: true }).getImageData(x, y, w, h).data;
    } catch {
        return fallback;
    }

    // Pixels grouped by near colour, each group's own average kept: a white page comes back #ffffff, not the
    // middle of its group.
    const groups = new Map();

    for (let i = 0; i < pixels.length; i += 4) {
        const key = ((pixels[i] >> 3) << 10) | ((pixels[i + 1] >> 3) << 5) | (pixels[i + 2] >> 3);
        const group = groups.get(key) ?? groups.set(key, { n: 0, sum: [0, 0, 0] }).get(key);
        group.n++;
        group.sum[0] += pixels[i];
        group.sum[1] += pixels[i + 1];
        group.sum[2] += pixels[i + 2];
    }

    const ranked = [...groups.values()].sort((one, two) => two.n - one.n).map((group) => group.sum.map((part) => Math.round(part / group.n)));
    const background = ranked[0];
    const distance = (color) => Math.hypot(...color.map((part, at) => part - background[at]));
    const text = ranked.find((color) => distance(color) > 120);
    const hex = (color) => '#' + color.map((part) => Math.min(255, part).toString(16).padStart(2, '0')).join('');

    return { text: text ? hex(text) : (distance([0, 0, 0]) > 120 ? '#000000' : '#ffffff'), background: hex(background) };
}
