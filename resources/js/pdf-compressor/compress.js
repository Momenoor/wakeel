// A PDF made smaller in the browser, the way a desktop tool would:
//   1. what is in the file but never shown goes — objects nothing refers to any more (left by edits and earlier
//      saves), page thumbnails, and the private data an application keeps for itself (PieceInfo);
//   2. what is in it twice is kept once — the same font, colour profile or picture in every part of a merged file;
//   3. its pictures — the scanned pages that make most court papers heavy — drawn again at a lower resolution and
//      saved as JPEG at the level's quality, whatever kind they are: what the browser reads itself (JPEG, 8-bit RGB
//      or grey) directly, anything else (JPEG 2000, CMYK, a palette, 16 bits, Lab, a Decode array …) drawn by
//      pdf.js first (draw-image.js); several at once, each in a worker of its own (image-pool.js, encode-image.js);
//   4. its text, lines and fonts kept as they are (still sharp, still searchable), but packed: a stream stored
//      uncompressed, or in an old encoding (ASCII, LZW, run length), compressed again with Flate; its small objects
//      packed together (object streams).
// A picture that would not come out clearly smaller, a black-and-white one (already as small as it gets), a mask,
// or one that cannot be decoded, is left as it was.
import { zlibSync } from 'fflate';
import { PDFArray, PDFBool, PDFDict, PDFName, PDFNumber, PDFRawStream, PDFRef, PDFStream, decodePDFRawStream } from 'pdf-lib';
import { loadPdf, pause } from '../pdf-splitter/split.js';
import { imagePool } from './image-pool.js';

// The longest side a picture keeps (an A4 page's long side is 11.7 inches: 1500 px is about 130 dpi, a screen's
// sharpness), and the JPEG quality it is saved at.
export const LEVELS = {
    light: { maxSide: 2000, quality: 0.7 },
    balanced: { maxSide: 1500, quality: 0.55 },
    strong: { maxSide: 1150, quality: 0.45 },
    maximum: { maxSide: 900, quality: 0.35 },
};

// A picture smaller than this is not worth redrawing. Small, because a scanner may cut each page into strips of
// a few kilobytes each: thousands of them can make most of a file.
const SMALLEST = 2_000;

// The encodings a stream can be decoded from here, to be packed again with Flate.
const PACKABLE = new Set(['FlateDecode', 'LZWDecode', 'ASCII85Decode', 'ASCIIHexDecode', 'RunLengthDecode']);

const N = (name) => PDFName.of(name);

export async function compressPdf(bytes, level, { signal, workerUrl, onImage = () => {} }) {
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
    let done = 0;
    let drawer = null;
    const pool = await imagePool(workerUrl);
    const drawerOf = async () => (drawer ??= (await import('./draw-image.js')).imageDrawer());

    try {
        // Several pictures on the way at once, each made smaller in a worker of its own.
        await atOnce(images, pool.size, signal, async ([ref, image]) => {
            let outcome = masks.has(ref.toString()) ? 'mask' : plan(context, image, settings);

            if (typeof outcome !== 'string') {
                try {
                    outcome = await shrink(context, image, outcome, pool, drawerOf);
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

            onImage(++done, images.length);
        });
    } finally {
        pool.close();
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

// `task` for each item, `limit` of them at a time.
async function atOnce(items, limit, signal, task) {
    let next = 0;

    const runner = async () => {
        while (next < items.length) {
            await task(items[next++]);
            await pause(signal);
        }
    };

    await Promise.all(Array.from({ length: Math.min(limit, items.length) }, runner));
}

// How a picture is to be made smaller — its size to be, and how it is read — or why it is left as it was.
function plan(context, image, { maxSide, quality }) {
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
    const channels = channelsOf(context, dict.get(N('ColorSpace')));
    const lossy = filters.some((filter) => filter === 'DCTDecode' || filter === 'JPXDecode');

    return {
        w: Math.max(1, Math.round(width * scale)),
        h: Math.max(1, Math.round(height * scale)),
        quality,
        // A picture that was not saved as JPEG (a grey scan of a typed page, mostly white, packs small without loss)
        // is also tried without loss at the lower resolution: grey or RGB.
        lossless: lossy ? 0 : channels === 1 ? 1 : 3,
        // Not worth it unless it comes out clearly smaller.
        limit: image.contents.length * 0.9,
        source: directly(image, filters, bits, channels, width, height),
    };
}

// How the browser reads the picture itself — a JPEG in RGB or grey (also one compressed again with Flate, as some
// scanners save it), or 8-bit RGB or grey samples, as they are or with Flate and PNG's predictor — or null for
// pdf.js to draw it.
function directly(image, filters, bits, channels, width, height) {
    const params = image.dict.get(N('DecodeParms'));

    if (! channels || image.dict.has(N('Decode'))) {
        return null;
    }

    if (filters.at(-1) === 'DCTDecode') {
        const outer = filters.slice(0, -1);

        return outer.length === 0 || (outer.length === 1 && outer[0] === 'FlateDecode' && ! params) ? { kind: 'jpeg', inflate: outer.length === 1 } : null;
    }

    if (bits !== 8 || filters.length > 1 || (filters.length === 1 && filters[0] !== 'FlateDecode')) {
        return null;
    }

    const predictor = params instanceof PDFDict ? numberOf(params.get(N('Predictor'))) : 1;

    if (predictor > 1 && (predictor < 10 || (numberOf(params.get(N('Colors'))) || 1) !== channels)) {
        return null; // TIFF's predictor, or one that does not match the picture: pdf.js draws it.
    }

    return { kind: 'samples', inflate: filters.length === 1, width, height, channels, columns: predictor > 1 ? numberOf(params.get(N('Columns'))) || width : 0 };
}

// The picture made smaller as a new image stream, or 'no-gain'. Read directly in a worker when it can be; drawn by
// pdf.js on the page (then made smaller in a worker) when not, or when reading it directly failed.
async function shrink(context, image, { source, ...job }, pool, drawerOf) {
    let result;
    let read = false;

    if (source) {
        try {
            // A copy: the picture's bytes are handed over to the worker, and the file's own must stay.
            result = await pool.run({ ...job, source: { ...source, bytes: image.contents.slice() } });
            read = true;
        } catch {
            // Not for the browser after all: pdf.js draws it.
        }
    }

    if (! read) {
        const canvas = await (await drawerOf()).draw(context, image, job.w, job.h);
        result = await pool.run({ ...job, source: { kind: 'bitmap', bitmap: await createImageBitmap(canvas) } });
    }

    if (! result) {
        return 'no-gain';
    }

    const redrawn = context.obj({
        Type: 'XObject',
        Subtype: 'Image',
        Width: job.w,
        Height: job.h,
        BitsPerComponent: 8,
        ...(result.kind === 'jpeg'
            ? { ColorSpace: 'DeviceRGB', Filter: 'DCTDecode' }
            : {
                  ColorSpace: result.channels === 1 ? 'DeviceGray' : 'DeviceRGB',
                  Filter: 'FlateDecode',
                  DecodeParms: { Predictor: 15, Colors: result.channels, BitsPerComponent: 8, Columns: job.w },
              }),
    });

    // Its soft mask (transparency) and how it is to be shown stay with it.
    for (const key of ['SMask', 'Interpolate', 'Intent']) {
        if (image.dict.has(N(key))) {
            redrawn.set(N(key), image.dict.get(N(key)));
        }
    }

    return PDFRawStream.of(redrawn, result.bytes);
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
