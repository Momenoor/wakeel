// Writing the placed signatures, stamps and text into the PDF with pdf-lib. Everything else in the file is
// kept as it was: the pages, their text, links and metadata. (pdf-lib wraps a page's own drawing in q … Q
// before adding to it, so whatever that drawing leaves behind — a scale, a flipped axis — cannot move ours.)
import { PDFDocument, degrees } from 'pdf-lib';
import { pdfPlacement } from './geometry.js';
import { pngBytes } from './images.js';

export async function stampPdf(original, items, viewportOf) {
    const pdf = await PDFDocument.load(original, { updateMetadata: false });
    const images = new Map();

    for (const item of items) {
        const page = pdf.getPage(item.page);

        // One copy of each picture in the file, however many pages it is on.
        if (! images.has(item.src)) {
            images.set(item.src, await pdf.embedPng(await pngBytes(item.src)));
        }

        const { x, y, width, height, angle } = pdfPlacement(viewportOf(item.page), item);
        page.drawImage(images.get(item.src), { x, y, width, height, rotate: degrees(angle) });
    }

    return pdf.save();
}
