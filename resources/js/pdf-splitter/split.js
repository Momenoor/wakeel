// Splitting a PDF with pdf-lib, in the browser: so many pages a part, or as many pages as fit under a size.
//
// By size, without saving the parts over and over: each page's cost is estimated once from the objects it
// draws on (its contents, fonts, pictures — each counted once a part however many of its pages use it), the
// parts are packed by those estimates, and each part is then saved for real. Only a part that came out over the
// limit is saved again, with its last pages moved on to the next part. The estimates are corrected as parts
// come out (a saved file packs its small objects tighter than they are counted), so later parts fit better.
import { PDFArray, PDFDict, PDFDocument, PDFName, PDFNull, PDFObjectCopier, PDFPage, PDFRef, PDFStream, ParseSpeeds } from 'pdf-lib';

export class Cancelled extends Error {}

export class Protected extends Error {}

// What an object costs in the file besides itself: "12 0 obj" … "endobj", and its line in the table at the end.
const OBJECT_OVERHEAD = 40;
// A part's own objects: its catalog, page tree, trailer.
const PART_OVERHEAD = 2048;
// Parts are packed a little under the limit, so fewer of them have to be saved twice.
const HEADROOM = 0.97;

const PARENT = PDFName.of('Parent');
const INHERITED = ['Resources', 'MediaBox', 'CropBox', 'Rotate'].map((name) => PDFName.of(name));

// Gives the browser a moment — to draw the progress, take a click on Cancel — every so often, not every step.
let lastPause = 0;

export async function pause(signal) {
    if (performance.now() - lastPause > 30) {
        await new Promise((resolve) => setTimeout(resolve, 0));
        lastPause = performance.now();
    }

    if (signal?.cancelled) {
        throw new Cancelled();
    }
}

export async function loadPdf(bytes) {
    try {
        // Encrypted files are refused (ignoreEncryption stays false): pdf-lib cannot write them back.
        return await PDFDocument.load(bytes, { updateMetadata: false, parseSpeed: ParseSpeeds.Fast });
    } catch (error) {
        // pdf-lib's EncryptedPDFError, told by its message (its class name does not survive the build).
        throw /encrypted/i.test(error?.message ?? '') ? new Protected() : error;
    }
}

// What each page needs from the file — the objects it reaches, each with its size — once per document.
//
// A page's links to other pages and to the page tree (a link's destination, an annotation's own page, a form
// field's other widgets) are cut on the way: copied along, each would bring that other page's whole contents —
// or every page — into the part (pdf-lib copies whatever is referred to). Such a link could not work in a part
// anyway: pdf-lib would point it at a copy of its own, never at the page as it is in the part.
export async function analyse(doc, { signal, onPage }) {
    await doc.flush();

    const pages = doc.getPages();
    const tree = new Set([doc.catalog.get(PDFName.of('Pages'))]);
    doc.catalog.Pages().traverse((node, ref) => tree.add(ref));
    const sizes = new Map();
    const costs = [];

    for (let index = 0; index < pages.length; index++) {
        const refs = [...reach(doc.context, pages[index].node, tree)];

        for (const ref of refs) {
            if (! sizes.has(ref)) {
                sizes.set(ref, (doc.context.lookup(ref)?.sizeInBytes() ?? 0) + OBJECT_OVERHEAD);
            }
        }

        costs.push({ own: pages[index].node.sizeInBytes() + OBJECT_OVERHEAD, refs });
        onPage(index + 1, pages.length);
        await pause(signal);
    }

    return { costs, sizes };
}

// The objects a page reaches, other than the page tree and the pages in it (whose links are cut here).
function reach(context, leaf, tree) {
    const found = new Set();
    const queue = [];

    const visit = (value) => {
        if (value instanceof PDFRef) {
            if (! found.has(value)) {
                found.add(value);
                const object = context.lookup(value);
                object && queue.push(object);
            }
        } else if (value instanceof PDFDict || value instanceof PDFArray || value instanceof PDFStream) {
            queue.push(value);
        }
    };

    // As pdf-lib copies a page: without its parent, with what it inherits from it.
    for (const [key, value] of leaf.entries()) {
        key !== PARENT && visit(value);
    }

    for (const key of INHERITED) {
        if (! leaf.get(key)) {
            const value = leaf.getInheritableAttribute(key);
            value && visit(value);
        }
    }

    while (queue.length) {
        const object = queue.pop();
        const container = object instanceof PDFStream ? object.dict : object;

        if (container instanceof PDFDict) {
            for (const [key, value] of container.entries()) {
                tree.has(value) ? container.delete(key) : visit(value);
            }
        } else if (container instanceof PDFArray) {
            for (let index = 0; index < container.size(); index++) {
                const value = container.get(index);
                tree.has(value) ? container.set(index, PDFNull) : visit(value);
            }
        }
    }

    return found;
}

// A new PDF of these pages (indexes), saved. One copier for the whole part: what its pages share is copied once.
async function savePart(doc, indexes, { signal, onPage }) {
    const part = await PDFDocument.create({ updateMetadata: false });
    const copier = PDFObjectCopier.for(doc.context, part.context);
    const pages = doc.getPages();

    for (const index of indexes) {
        const node = copier.copy(pages[index].node);
        part.addPage(PDFPage.of(node, part.context.register(node), part));
        onPage(index + 1);
        await pause(signal);
    }

    return part.save({ objectsPerTick: 200 });
}

const range = (from, to) => Array.from({ length: to - from }, (_, offset) => from + offset);

// Parts of so many pages each.
export async function splitByPages(doc, perPart, { signal, onPart, onPage }) {
    const count = doc.getPageCount();
    const total = Math.ceil(count / perPart);
    const parts = [];

    for (let start = 0; start < count; start += perPart) {
        onPart(parts.length + 1, total);
        const indexes = range(start, Math.min(start + perPart, count));
        const bytes = await savePart(doc, indexes, { signal, onPage });
        parts.push(finished(indexes, bytes, Infinity));
    }

    return parts;
}

// Parts each under so many bytes, as few as the estimates allow. A page bigger than the limit on its own goes
// into a part of its own, marked as over.
export async function splitBySize(doc, analysis, limit, { signal, onPart, onPage }) {
    const { costs, sizes } = analysis;
    const parts = [];
    // Saved bytes against estimated, over the parts so far: how far the estimates are to be trusted.
    let saved = 0;
    let estimated = 0;
    let next = 0;

    const clamp = (factor) => Math.min(Math.max(factor, 0.05), 4);

    // As many pages from `start` as fit by the estimates (scaled by `factor`), and what each one added.
    const pack = (start, factor) => {
        const indexes = [];
        const added = [];
        const seen = new Set();
        let estimate = PART_OVERHEAD;

        for (let index = start; index < costs.length; index++) {
            const fresh = costs[index].refs.filter((ref) => ! seen.has(ref));
            const cost = costs[index].own + fresh.reduce((sum, ref) => sum + sizes.get(ref), 0);

            if (indexes.length && (estimate + cost) * factor > limit * HEADROOM) {
                break;
            }

            fresh.forEach((ref) => seen.add(ref));
            indexes.push(index);
            added.push(cost);
            estimate += cost;
        }

        return { indexes, added, estimate };
    };

    while (next < costs.length) {
        let { indexes, added, estimate } = pack(next, estimated ? clamp(saved / estimated) : 1);

        onPart(parts.length + 1, null);
        let bytes = await savePart(doc, indexes, { signal, onPage });

        // The first part is packed before any estimate could be checked against a saved file, and may have come
        // out well under: packed once more, by what its own file showed, when that takes more pages.
        if (! estimated && bytes.length < limit * 0.85 && indexes[indexes.length - 1] + 1 < costs.length) {
            const again = pack(next, clamp(bytes.length / estimate));

            if (again.indexes.length > indexes.length) {
                ({ indexes, added, estimate } = again);
                bytes = await savePart(doc, indexes, { signal, onPage });
            }
        }

        // Over after all: the last pages move on — as many as the excess is worth by their estimates, scaled to
        // what this part actually came to — and the rest is saved again.
        while (bytes.length > limit && indexes.length > 1) {
            const scale = bytes.length / estimate;
            let excess = bytes.length - limit * HEADROOM;

            do {
                indexes.pop();
                const cost = added.pop();
                estimate -= cost;
                excess -= cost * scale;
            } while (excess > 0 && indexes.length > 1);

            bytes = await savePart(doc, indexes, { signal, onPage });
        }

        saved += bytes.length;
        estimated += estimate;
        parts.push(finished(indexes, bytes, limit));
        next = indexes[indexes.length - 1] + 1;
    }

    return parts;
}

// A part as the page keeps it: its pages (counted from 1), its file, and whether it is over the limit — only
// ever a single page, too big on its own.
function finished(indexes, bytes, limit) {
    return {
        from: indexes[0] + 1,
        to: indexes[indexes.length - 1] + 1,
        size: bytes.length,
        over: bytes.length > limit,
        blob: new Blob([bytes], { type: 'application/pdf' }),
    };
}
