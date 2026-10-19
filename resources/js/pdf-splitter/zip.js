// The parts in one zip, made in the browser with fflate. Stored, not compressed: a PDF's insides are compressed
// already, and squeezing them again would take long for nothing. Each file's share of the zip is handed to a
// Blob as soon as it is written, so the page holds no more than one part's bytes at a time beside the parts.
import { Zip, ZipPassThrough, strToU8 } from 'fflate';
import { pause } from './split.js';

// files: [{ name, blob }] or [{ name, text }]
export async function zipFiles(files, signal) {
    const pieces = [];
    let chunks = [];
    let failure = null;

    const zip = new Zip((error, chunk) => {
        error ? (failure = error) : chunks.push(chunk);
    });

    for (const file of files) {
        const entry = new ZipPassThrough(file.name);
        zip.add(entry);
        entry.push(file.text !== undefined ? strToU8(file.text) : new Uint8Array(await file.blob.arrayBuffer()), true);

        if (failure) {
            throw failure;
        }

        pieces.push(new Blob(chunks));
        chunks = [];
        await pause(signal);
    }

    zip.end();

    if (failure) {
        throw failure;
    }

    pieces.push(new Blob(chunks));

    return new Blob(pieces, { type: 'application/zip' });
}
