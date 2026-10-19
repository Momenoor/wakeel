// Pictures for the editor: a library image's size, any picture as PNG bytes for pdf-lib (which takes only
// PNG and JPEG — a WEBP stamp is redrawn as a PNG), and a text box drawn as a picture (so Arabic is joined
// and laid out by the browser, never by a PDF font that cannot).

const loaded = new Map();

export function loadImage(src) {
    if (! loaded.has(src)) {
        loaded.set(src, new Promise((resolve, reject) => {
            const image = new Image();
            image.decoding = 'async';
            image.onload = () => resolve(image);
            image.onerror = () => {
                loaded.delete(src);
                reject(new Error('image'));
            };
            image.src = src;
        }));
    }

    return loaded.get(src);
}

// No larger than this on its longest side: enough for print, small enough for the PDF.
const MAX_SIDE = 2400;

export async function pngBytes(src) {
    const image = await loadImage(src);
    const scale = Math.min(1, MAX_SIDE / Math.max(image.naturalWidth, image.naturalHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
    canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
    canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

    return new Uint8Array(await blob.arrayBuffer());
}

const ARABIC = /[֐-ࣿיִ-﷿ﹰ-﻿]/;

// A text box as a transparent PNG: each line centred, in the page's own font. Returns its data URL and
// its aspect (width ÷ height) — the box keeps its line height when the text changes.
export function textImage(text, { color = '#111827', font = null } = {}) {
    const lines = String(text || ' ').split(/\r?\n/);
    const size = 64;
    const lineHeight = Math.round(size * 1.3);
    const family = font || getComputedStyle(document.body).fontFamily || 'sans-serif';
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    context.font = `${size}px ${family}`;
    const width = Math.ceil(Math.max(...lines.map((line) => context.measureText(line || ' ').width), size)) + size / 2;

    canvas.width = width;
    canvas.height = lineHeight * lines.length;
    context.font = `${size}px ${family}`;
    context.fillStyle = color;
    context.textAlign = 'center';
    context.textBaseline = 'middle';

    lines.forEach((line, index) => {
        context.direction = ARABIC.test(line) ? 'rtl' : 'ltr';
        context.fillText(line, width / 2, lineHeight * index + lineHeight / 2);
    });

    return { src: canvas.toDataURL('image/png'), aspect: canvas.width / canvas.height, lines: lines.length };
}
