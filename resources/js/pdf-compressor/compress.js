// A PDF made smaller in the browser, the way a desktop tool would:
//   1. what is in the file but never shown goes — objects nothing refers to any more (left by edits and earlier
//      saves), page thumbnails, and the private data an application keeps for itself (PieceInfo);
//   2. what is in it twice is kept once — the same font, colour profile or picture in every part of a merged file;
//   3. its pictures — the scanned pages that make most court papers heavy — drawn again at a lower resolution and
//      saved as JPEG at the level's quality, whatever kind they are: what the browser reads itself (JPEG, 8-bit RGB
//      or grey) directly, anything else (JPEG 2000, CMYK, a palette, 16 bits, Lab, a Decode array …) through
//      pdf.js (draw-image.js);
//   4. its text, lines and fonts kept as they are (still sharp, still searchable), but packed: a stream stored
//      uncompressed, or in an old encoding (ASCII, LZW, run length), compressed again with Flate; its small objects
//      packed together (object streams).
// A picture that would not come out clearly smaller, a black-and-white one (already as small as it gets), a mask,
// or one that cannot be decoded, is left as it was.
import { zlibSync } from 'fflate';
import { PDFArray, PDFBool, PDFDict, PDFName, PDFNumber, PDFRawStream, PDFRef, PDFStream, decodePDFRawStream } from 'pdf-lib';
import { loadPdf, pause } from '../pdf-splitter/split.js';

// The longest side a picture keeps (an A4 page's long side is 11.7 inches: 1750 px is about 150 dpi, a screen's
// sharpness), and the JPEG quality it is saved at.
export const LEVELS = {
    light: { maxSide: 2400, quality: 0.75 },
    balanced: { maxSide: 1750, quality: 0.6 },
    strong: { maxSide: 1200, quality: 0.45 },
};

// A picture smaller than this is not worth redrawing. Small, because a scanner may cut each page into strips of
// a few kilobytes each: thousands of them can make most of a file.
const SMALLEST = 2_000;

// The encodings a stream can be decoded from here, to be packed again with Flate.
const PACKABLE = new Set(['FlateDecode', 'LZWDecode', 'ASCII85Decode', 'ASCIIHexDecode', 'RunLengthDecode']);

const N = (name) => PDFName.of(name);

export async function compressPdf(bytes, level, { signal, onImage = () => {} }) {
    const settings = LEVELS[level] ?? LEVELS.balanced;
    const doc = await loadPdf(bytes);
    const context = doc.context;

    for (const holder of [doc.catalog, ...doc.getPages().map((page) => page.node)]) {
        holder.delete(N('PieceInfo'));
        holder.delete(N('Thumb'));
    }

    await pause(signal);
    const twins = await keepOnce(context, signal);
    collectGarbage(context);

    const images = [];
    // Masks stay as they are: a soft mask must stay grey, a stencil one bit deep.
    const masks = new Set();

    for (const [ref, object] of context.enumerateIndirectObjects()) {
        if (object instanceof PDFRawStream && object.dict.get(N('Subtype')) === N('Image')) {
            images.push([ref, object]);

            for (const key of ['SMask', 'Mask']) {
                const mask = object.dict.get(N(key));

                if (mask instanceof PDFRef) {
                    masks.add(mask.toString());
                }
            }
        }
    }

    const kept = {};
    let redrawn = 0;
    let drawer = null;

    try {
        for (let index = 0; index < images.length; index++) {
            const [ref, image] = images[index];
            onImage(index + 1, images.length);

            let outcome = 'mask';

            if (! masks.has(ref.toString())) {
                try {
                    outcome = await redraw(context, image, settings, async () => (drawer ??= (await import('./draw-image.js')).imageDrawer()));
                } catch (error) {
                    // Left as it was: one picture that will not decode does not stop the rest.
                    console.warn('Compress PDF: a picture left as it was', ref.toString(), error);
                    outcome = 'unreadable';
                }
            }

            if (outcome instanceof PDFRawStream) {
                context.assign(ref, outcome);
                redrawn++;
            } else {
                kept[outcome] = (kept[outcome] ?? 0) + 1;
            }

            await pause(signal);
        }
    } finally {
        drawer?.close();
    }

    // What the pictures redrawn no longer use (their colour profiles, palettes, JBIG2 dictionaries) goes too.
    collectGarbage(context);
    const packed = await pack(context, signal);

    const saved = await doc.save({ useObjectStreams: true, objectsPerTick: 200 });
    // Never larger than it came: a PDF with nothing to gain is given back as it was.
    const result = saved.length < bytes.length ? saved : bytes;

    console.info('Compress PDF', { level, before: bytes.length, after: saved.length, images: images.length, redrawn, kept, twins, packed });

    return { blob: new Blob([result], { type: 'application/pdf' }), pages: doc.getPageCount(), images: images.length, redrawn, smaller: result !== bytes };
}

// The picture drawn again, smaller, as a new image stream — or why it is left as it was.
async function redraw(context, image, { maxSide, quality }, drawer) {
    const dict = image.dict;
    const filters = filtersOf(dict);
    const bits = numberOf(dict.get(N('BitsPerComponent')));

    if (image.contents.length < SMALLEST) {
        return 'small';
    }

    if (dict.get(N('ImageMask')) === PDFBool.True || dict.get(N('Mask')) instanceof PDFArray) {
        return 'mask';
    }

    // Black and white (a fax-like scan): CCITT and JBIG2 keep it far smaller than any JPEG could.
    if (bits === 1 || filters.includes('CCITTFaxDecode') || filters.includes('JBIG2Decode')) {
        return 'black-and-white';
    }

    const width = numberOf(dict.get(N('Width')));
    const height = numberOf(dict.get(N('Height')));

    if (! width || ! height) {
        return 'unreadable';
    }

    const scale = Math.min(1, maxSide / Math.max(width, height));
    const w = Math.max(1, Math.round(width * scale));
    const h = Math.max(1, Math.round(height * scale));
    const channels = channelsOf(context, dict.get(N('ColorSpace')));
    const picture = (await readDirectly(context, image, filters, bits, channels, width, height)) ?? (await (await drawer()).draw(context, image, w, h));
    const canvas = paint(picture, w, h);
    picture.close?.();

    // As JPEG; and a picture that was not saved as one (a grey scan of a typed page, mostly white, packs small
    // without loss) also kept without loss at the lower resolution: whichever is smaller.
    const candidates = [await jpegOf(canvas, quality)];

    if (! filters.some((filter) => filter === 'DCTDecode' || filter === 'JPXDecode')) {
        candidates.push(losslessOf(canvas, channels === 1 ? 1 : 3));
    }

    const best = candidates.reduce((a, b) => (b.bytes.length < a.bytes.length ? b : a));

    // Not worth it unless it comes out clearly smaller.
    if (best.bytes.length >= image.contents.length * 0.9) {
        return 'no-gain';
    }

    const redrawn = context.obj({ Type: 'XObject', Subtype: 'Image', Width: w, Height: h, BitsPerComponent: 8, ...best.dict });

    // Its soft mask (transparency) and how it is to be shown stay with it.
    for (const key of ['SMask', 'Interpolate', 'Intent']) {
        if (dict.has(N(key))) {
            redrawn.set(N(key), dict.get(N(key)));
        }
    }

    return PDFRawStream.of(redrawn, best.bytes);
}

// The picture as the browser reads it itself — a JPEG in RGB or grey (also one compressed again inside the PDF,
// Flate over JPEG, as some scanners save it), or 8-bit RGB or grey samples — or null for pdf.js to draw it.
async function readDirectly(context, image, filters, bits, channels, width, height) {
    if (! channels || image.dict.has(N('Decode'))) {
        return null;
    }

    try {
        if (filters.at(-1) === 'DCTDecode') {
            const outer = filters.slice(0, -1);

            if (! outer.every((filter) => PACKABLE.has(filter)) || (outer.length && image.dict.has(N('DecodeParms')))) {
                return null;
            }

            const jpeg = outer.length ? decodePDFRawStream(PDFRawStream.of(context.obj({ Filter: outer }), image.contents)).decode() : image.contents;

            return await createImageBitmap(new Blob([jpeg], { type: 'image/jpeg' }));
        }

        if (filters.length <= 1 && (filters[0] ?? 'FlateDecode') === 'FlateDecode' && bits === 8) {
            const pixels = rawPixels(image, filters.length === 1, channels, width);

            return pixels && pixels.length >= width * height * channels ? await createImageBitmap(toImageData(pixels, width, height, channels)) : null;
        }
    } catch {
        // Not for the browser after all: pdf.js draws it.
    }

    return null;
}

// Streams alike in every byte (and in their dictionaries) kept once, everything that pointed at a copy pointed at
// the one kept. Twice over: a picture is only seen alike once its colour profile is.
async function keepOnce(context, signal) {
    let twins = 0;

    for (let round = 0; round < 2; round++) {
        const groups = new Map();
        const swap = new Map();

        for (const [ref, object] of context.enumerateIndirectObjects()) {
            if (! (object instanceof PDFRawStream)) {
                continue;
            }

            const key = `${object.contents.length}|${object.dict.toString()}`;
            const group = groups.get(key) ?? [];
            const twin = group.find(([, other]) => sameBytes(other.contents, object.contents));

            if (twin) {
                swap.set(ref.toString(), twin[0]);
            } else {
                group.push([ref, object]);
                groups.set(key, group);
            }

            await pause(signal);
        }

        if (! swap.size) {
            break;
        }

        twins += swap.size;
        repoint(context, swap);
    }

    return twins;
}

function sameBytes(a, b) {
    if (a.length !== b.length) {
        return false;
    }

    for (let i = 0; i < a.length; i++) {
        if (a[i] !== b[i]) {
            return false;
        }
    }

    return true;
}

// Every reference to one of `swap`'s objects pointed at the object kept in its place.
function repoint(context, swap) {
    const visit = (object) => {
        const container = object instanceof PDFStream ? object.dict : object;

        if (container instanceof PDFDict) {
            for (const [key, value] of container.entries()) {
                const kept = value instanceof PDFRef ? swap.get(value.toString()) : null;
                kept ? container.set(key, kept) : visit(value);
            }
        } else if (container instanceof PDFArray) {
            for (let i = 0; i < container.size(); i++) {
                const value = container.get(i);
                const kept = value instanceof PDFRef ? swap.get(value.toString()) : null;
                kept ? container.set(i, kept) : visit(value);
            }
        }
    };

    for (const [, object] of context.enumerateIndirectObjects()) {
        visit(object);
    }
}

// Objects nothing in the document reaches — from its catalog or its information — deleted.
function collectGarbage(context) {
    const reached = new Set();
    const queue = [context.trailerInfo.Root, context.trailerInfo.Info];

    while (queue.length) {
        let object = queue.pop();

        if (object instanceof PDFRef) {
            if (reached.has(object.toString())) {
                continue;
            }

            reached.add(object.toString());
            object = context.lookup(object);
        }

        const container = object instanceof PDFStream ? object.dict : object;

        if (container instanceof PDFDict) {
            queue.push(...container.values());
        } else if (container instanceof PDFArray) {
            queue.push(...container.asArray());
        }
    }

    for (const [ref] of context.enumerateIndirectObjects()) {
        if (! reached.has(ref.toString())) {
            context.delete(ref);
        }
    }
}

// Streams stored uncompressed or in an old encoding, compressed with Flate when that makes them smaller.
async function pack(context, signal) {
    let packed = 0;

    for (const [ref, object] of context.enumerateIndirectObjects()) {
        if (! (object instanceof PDFRawStream) || object.contents.length < 256 || object.dict.has(N('F'))) {
            continue;
        }

        const filters = filtersOf(object.dict);

        if ((filters.length === 1 && filters[0] === 'FlateDecode') || ! filters.every((filter) => PACKABLE.has(filter)) || (filters.length && object.dict.has(N('DecodeParms')))) {
            continue;
        }

        try {
            const data = filters.length ? decodePDFRawStream(object).decode() : object.contents;
            const smaller = zlibSync(data, { level: 9 });

            if (smaller.length < object.contents.length) {
                const dict = object.dict.clone(context);
                dict.delete(N('DecodeParms'));
                dict.set(N('Filter'), N('FlateDecode'));
                context.assign(ref, PDFRawStream.of(dict, smaller));
                packed++;
            }
        } catch {
            // Left as it was.
        }

        await pause(signal);
    }

    return packed;
}

const numberOf = (value) => (value instanceof PDFNumber ? value.asNumber() : 0);

function filtersOf(dict) {
    const filter = dict.get(N('Filter'));

    if (filter instanceof PDFName) {
        return [filter.decodeText()];
    }

    return filter instanceof PDFArray ? filter.asArray().map((name) => (name instanceof PDFName ? name.decodeText() : '?')) : [];
}

// 3 for an RGB picture, 1 for a grey one; null for any other (CMYK, a palette, a separation …).
function channelsOf(context, space) {
    const resolved = space instanceof PDFRef ? context.lookup(space) : space;

    if (resolved instanceof PDFName) {
        return { DeviceRGB: 3, CalRGB: 3, DeviceGray: 1, CalGray: 1 }[resolved.decodeText()] ?? null;
    }

    if (resolved instanceof PDFArray) {
        const kind = resolved.get(0);
        const name = kind instanceof PDFName ? kind.decodeText() : '';

        if (name === 'CalRGB') {
            return 3;
        }

        if (name === 'CalGray') {
            return 1;
        }

        if (name === 'ICCBased') {
            const profile = context.lookup(resolved.get(1));
            const count = numberOf(profile?.dict?.get(N('N')));

            return count === 3 || count === 1 ? count : null;
        }
    }

    return null;
}

// The picture's samples, a row after another: inflated, its PNG predictor undone if it has one.
function rawPixels(image, compressed, channels, width) {
    const data = compressed ? decodePDFRawStream(image).decode() : image.contents;
    const params = image.dict.get(N('DecodeParms'));
    const predictor = params instanceof PDFDict ? numberOf(params.get(N('Predictor'))) : 1;

    if (predictor <= 1) {
        return data;
    }

    if (predictor < 10) {
        return null; // TIFF's predictor: pdf.js draws it.
    }

    const colors = numberOf(params.get(N('Colors'))) || 1;
    const columns = numberOf(params.get(N('Columns'))) || width;

    return colors === channels ? unpredict(data, colors, columns) : null;
}

// PNG's row filters undone (None, Sub, Up, Average, Paeth), 8 bits a sample.
function unpredict(data, bytesPerPixel, columns) {
    const rowLength = bytesPerPixel * columns;
    const rows = Math.floor(data.length / (rowLength + 1));
    const out = new Uint8Array(rows * rowLength);
    let previous = new Uint8Array(rowLength);

    for (let row = 0; row < rows; row++) {
        const start = row * (rowLength + 1);
        const type = data[start];
        const line = data.subarray(start + 1, start + 1 + rowLength);
        const current = out.subarray(row * rowLength, (row + 1) * rowLength);

        for (let i = 0; i < rowLength; i++) {
            const left = i >= bytesPerPixel ? current[i - bytesPerPixel] : 0;
            const up = previous[i];
            const upLeft = i >= bytesPerPixel ? previous[i - bytesPerPixel] : 0;
            let value = line[i];

            if (type === 1) {
                value += left;
            } else if (type === 2) {
                value += up;
            } else if (type === 3) {
                value += (left + up) >> 1;
            } else if (type === 4) {
                const p = left + up - upLeft;
                const pa = Math.abs(p - left);
                const pb = Math.abs(p - up);
                const pc = Math.abs(p - upLeft);
                value += pa <= pb && pa <= pc ? left : pb <= pc ? up : upLeft;
            }

            current[i] = value & 0xff;
        }

        previous = current;
    }

    return out;
}

function toImageData(pixels, width, height, channels) {
    const rgba = new Uint8ClampedArray(width * height * 4);

    for (let pixel = 0, source = 0; pixel < width * height; pixel++, source += channels) {
        const target = pixel * 4;

        if (channels === 1) {
            rgba[target] = rgba[target + 1] = rgba[target + 2] = pixels[source];
        } else {
            rgba[target] = pixels[source];
            rgba[target + 1] = pixels[source + 1];
            rgba[target + 2] = pixels[source + 2];
        }

        rgba[target + 3] = 255;
    }

    return new ImageData(rgba, width, height);
}

// The picture drawn at w × h on white (a JPEG has no transparency).
function paint(picture, w, h) {
    const canvas = typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas(w, h) : Object.assign(document.createElement('canvas'), { width: w, height: h });
    const context = canvas.getContext('2d', { willReadFrequently: true });
    context.fillStyle = '#fff';
    context.fillRect(0, 0, w, h);
    context.imageSmoothingQuality = 'high';
    context.drawImage(picture, 0, 0, w, h);

    return canvas;
}

async function jpegOf(canvas, quality) {
    const blob = canvas.convertToBlob
        ? await canvas.convertToBlob({ type: 'image/jpeg', quality })
        : await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));

    return { bytes: new Uint8Array(await blob.arrayBuffer()), dict: { ColorSpace: 'DeviceRGB', Filter: 'DCTDecode' } };
}

// The canvas's samples, grey (1) or RGB (3), each row after PNG's Up filter, packed with Flate.
function losslessOf(canvas, channels) {
    const { width: w, height: h } = canvas;
    const rgba = canvas.getContext('2d').getImageData(0, 0, w, h).data;
    const row = w * channels;
    const rows = new Uint8Array((row + 1) * h);

    for (let y = 0; y < h; y++) {
        const start = y * (row + 1);
        rows[start] = 2;

        for (let x = 0; x < w; x++) {
            for (let c = 0; c < channels; c++) {
                const at = (y * w + x) * 4 + c;
                rows[start + 1 + x * channels + c] = rgba[at] - (y ? rgba[at - w * 4] : 0);
            }
        }
    }

    return {
        bytes: zlibSync(rows, { level: 6 }),
        dict: {
            ColorSpace: channels === 1 ? 'DeviceGray' : 'DeviceRGB',
            Filter: 'FlateDecode',
            DecodeParms: { Predictor: 15, Colors: channels, BitsPerComponent: 8, Columns: w },
        },
    };
}
