// PDFs put together into one, in the order given, in the browser (pdf-lib) — and, when asked, a bookmark for each
// file at its first page, named after it, so the bundle reads like its parts.
import { PDFDocument, PDFHexString, PDFName, PDFNull, PDFNumber } from 'pdf-lib';
import { loadPdf, pause } from '../pdf-splitter/split.js';

// A file that could not be read: its name says which.
export class FileFailed extends Error {
    constructor(file, cause) {
        super(file);
        this.file = file;
        this.cause = cause;
    }
}

// sources: [{ name, bytes: async () => Uint8Array }]. onFile(number, total, name) as each one starts.
export async function mergePdfs(sources, { bookmarks = true, signal, onFile = () => {} }) {
    const merged = await PDFDocument.create({ updateMetadata: false });
    const marks = [];

    for (let index = 0; index < sources.length; index++) {
        const source = sources[index];
        onFile(index + 1, sources.length, source.name);

        let doc;

        try {
            doc = await loadPdf(await source.bytes());
        } catch (error) {
            throw new FileFailed(source.name, error);
        }

        const pages = await merged.copyPages(doc, doc.getPageIndices());

        if (pages.length) {
            marks.push({ title: source.name.replace(/\.pdf$/i, ''), page: pages[0] });
        }

        for (const page of pages) {
            merged.addPage(page);
            await pause(signal);
        }
    }

    if (bookmarks && marks.length > 1) {
        addBookmarks(merged, marks);
    }

    const bytes = await merged.save({ objectsPerTick: 200 });

    return { blob: new Blob([bytes], { type: 'application/pdf' }), pages: merged.getPageCount() };
}

// The document's outline: one entry per file, at the top of its first page.
function addBookmarks(doc, marks) {
    const context = doc.context;
    const outlinesRef = context.nextRef();
    const refs = marks.map(() => context.nextRef());

    marks.forEach((mark, index) => {
        const item = context.obj({
            Title: PDFHexString.fromText(mark.title),
            Parent: outlinesRef,
            Dest: [mark.page.ref, PDFName.of('XYZ'), PDFNull, PDFNull, PDFNull],
        });

        if (index > 0) {
            item.set(PDFName.of('Prev'), refs[index - 1]);
        }

        if (index < marks.length - 1) {
            item.set(PDFName.of('Next'), refs[index + 1]);
        }

        context.assign(refs[index], item);
    });

    context.assign(outlinesRef, context.obj({
        Type: 'Outlines',
        First: refs[0],
        Last: refs[refs.length - 1],
        Count: PDFNumber.of(marks.length),
    }));

    doc.catalog.set(PDFName.of('Outlines'), outlinesRef);
    // Opened with the bookmarks shown.
    doc.catalog.set(PDFName.of('PageMode'), PDFName.of('UseOutlines'));
}
