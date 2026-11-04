// One picture of a PDF made smaller: decoded (a JPEG, raw 8-bit RGB or grey samples, or a bitmap pdf.js drew),
// drawn at w × h on white, and saved as JPEG at the level's quality — and, for a picture that was not saved as one,
// also without loss (PNG's Up filter, then Flate): whichever is smaller. Null when neither comes under `limit`.
// Run in the compressor's workers (image-worker.js), several pictures at once; or here, where workers cannot be.
import { unzlibSync, zlibSync } from 'fflate';

export async function encodeImage({ source, w, h, quality, lossless, limit }) {
    const picture = await decode(source, w, h);
    const canvas = paint(picture, w, h, Boolean(lossless));
    picture.close?.();

    let best = await jpegOf(canvas, quality);

    if (lossless) {
        const packed = losslessOf(canvas, lossless);
        best = packed.bytes.length < best.bytes.length ? packed : best;
    }

    return best.bytes.length < limit ? best : null;
}

// The picture as a bitmap — decoded straight at w × h where the browser can, never at full size and then shrunk.
async function decode(source, w, h) {
    if (source.kind === 'bitmap') {
        return source.bitmap;
    }

    const resize = { resizeWidth: w, resizeHeight: h, resizeQuality: 'high' };
    const bytes = source.inflate ? unzlibSync(source.bytes) : source.bytes;

    if (source.kind === 'jpeg') {
        return createImageBitmap(new Blob([bytes], { type: 'image/jpeg' }), resize);
    }

    const { width, height, channels, columns } = source;
    const pixels = columns ? unpredict(bytes, channels, columns) : bytes;

    if (pixels.length < width * height * channels) {
        throw new Error('Fewer samples than the picture needs');
    }

    return createImageBitmap(toImageData(pixels, width, height, channels), resize);
}

function canvasOf(w, h) {
    return typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas(w, h) : Object.assign(document.createElement('canvas'), { width: w, height: h });
}

// The picture drawn at w × h on white (a JPEG has no transparency).
function paint(picture, w, h, toBeRead) {
    const canvas = canvasOf(w, h);
    const context = canvas.getContext('2d', { willReadFrequently: toBeRead });
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

    return { kind: 'jpeg', bytes: new Uint8Array(await blob.arrayBuffer()) };
}

// The canvas's samples, grey (1) or RGB (3), each row after PNG's Up filter, packed with Flate.
function losslessOf(canvas, channels) {
    const { width: w, height: h } = canvas;
    const rgba = canvas.getContext('2d').getImageData(0, 0, w, h).data;
    const row = w * channels;
    const rows = new Uint8Array((row + 1) * h);

    for (let y = 0, at = 0; y < h; y++) {
        let out = y * (row + 1);
        rows[out++] = 2;

        for (let x = 0; x < w; x++, at += 4) {
            for (let c = 0; c < channels; c++) {
                rows[out++] = rgba[at + c] - (y ? rgba[at + c - w * 4] : 0);
            }
        }
    }

    return { kind: 'flate', channels, bytes: zlibSync(rows, { level: 6 }) };
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
