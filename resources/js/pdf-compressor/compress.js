// A PDF made smaller in the browser: its pictures — the scanned pages that make most court papers heavy — drawn
// again at a lower resolution and saved as JPEG at the level's quality; its text, lines and fonts left as they are
// (still sharp, still searchable), its objects packed (object streams). A picture that would not come out smaller,
// or that this cannot redraw faithfully (CMYK, a palette, masks, other bit depths), is left as it was.
import { PDFArray, PDFBool, PDFDict, PDFName, PDFNumber, PDFRawStream, PDFRef, decodePDFRawStream } from 'pdf-lib';
import { loadPdf, pause } from '../pdf-splitter/split.js';

// The longest side a picture keeps (an A4 page's long side is 11.7 inches: 2000 px is about 170 dpi), and the
// JPEG quality it is saved at.
export const LEVELS = {
    light: { maxSide: 2800, quality: 0.8 },
    balanced: { maxSide: 2000, quality: 0.65 },
    strong: { maxSide: 1400, quality: 0.5 },
};

// A picture smaller than this is not worth redrawing.
const SMALLEST = 20_000;

const N = (name) => PDFName.of(name);

export async function compressPdf(bytes, level, { signal, onImage = () => {} }) {
    const settings = LEVELS[level] ?? LEVELS.balanced;
    const doc = await loadPdf(bytes);
    const context = doc.context;

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

    let redrawn = 0;

    for (let index = 0; index < images.length; index++) {
        const [ref, image] = images[index];
        onImage(index + 1, images.length);

        if (! masks.has(ref.toString())) {
            try {
                const smaller = await redraw(context, image, settings);

                if (smaller) {
                    context.assign(ref, smaller);
                    redrawn++;
                }
            } catch {
                // Left as it was: one picture that will not decode does not stop the rest.
            }
        }

        await pause(signal);
    }

    const saved = await doc.save({ useObjectStreams: true, objectsPerTick: 200 });
    // Never larger than it came: a PDF with nothing to gain is given back as it was.
    const result = saved.length < bytes.length ? saved : bytes;

    return { blob: new Blob([result], { type: 'application/pdf' }), pages: doc.getPageCount(), images: images.length, redrawn, smaller: result !== bytes };
}

// The picture drawn again, smaller, as a new image stream — or null to leave it.
async function redraw(context, image, { maxSide, quality }) {
    const dict = image.dict;

    if (image.contents.length < SMALLEST || dict.get(N('ImageMask')) === PDFBool.True || dict.has(N('Decode')) || dict.get(N('Mask')) instanceof PDFArray) {
        return null;
    }

    const width = numberOf(dict.get(N('Width')));
    const height = numberOf(dict.get(N('Height')));
    const filters = filtersOf(dict);
    const channels = channelsOf(context, dict.get(N('ColorSpace')));

    if (! width || ! height || ! channels) {
        return null;
    }

    let picture;

    if (filters.length === 1 && filters[0] === 'DCTDecode') {
        picture = await createImageBitmap(new Blob([image.contents], { type: 'image/jpeg' }));
    } else if (filters.length <= 1 && (filters[0] ?? 'FlateDecode') === 'FlateDecode' && numberOf(dict.get(N('BitsPerComponent'))) === 8) {
        const pixels = rawPixels(image, filters.length === 1, channels, width);

        if (! pixels || pixels.length < width * height * channels) {
            return null;
        }

        picture = await createImageBitmap(toImageData(pixels, width, height, channels));
    } else {
        return null;
    }

    const scale = Math.min(1, maxSide / Math.max(width, height));
    const w = Math.max(1, Math.round(width * scale));
    const h = Math.max(1, Math.round(height * scale));
    const jpeg = await encode(picture, w, h, quality);
    picture.close?.();

    // Not worth it unless it comes out clearly smaller.
    if (jpeg.length >= image.contents.length * 0.9) {
        return null;
    }

    const redrawn = context.obj({
        Type: 'XObject',
        Subtype: 'Image',
        Width: w,
        Height: h,
        ColorSpace: 'DeviceRGB',
        BitsPerComponent: 8,
        Filter: 'DCTDecode',
    });

    // Its soft mask (transparency) and how it is to be shown stay with it.
    for (const key of ['SMask', 'Interpolate', 'Intent']) {
        if (dict.has(N(key))) {
            redrawn.set(N(key), dict.get(N(key)));
        }
    }

    return PDFRawStream.of(redrawn, jpeg);
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
        return null; // TIFF's predictor: rare in pictures, left alone.
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

// The picture at w × h, on white (a JPEG has no transparency), as JPEG bytes.
async function encode(picture, w, h, quality) {
    const canvas = typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas(w, h) : Object.assign(document.createElement('canvas'), { width: w, height: h });
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, w, h);
    context.imageSmoothingQuality = 'high';
    context.drawImage(picture, 0, 0, w, h);

    const blob = canvas.convertToBlob
        ? await canvas.convertToBlob({ type: 'image/jpeg', quality })
        : await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));

    return new Uint8Array(await blob.arrayBuffer());
}
