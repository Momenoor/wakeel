// Writing the placed signatures, stamps and text into the PDF with pdf-lib. Everything else in the file is
// kept as it was: the pages, their text, links and metadata. (pdf-lib wraps a page's own drawing in q … Q
// before adding to it, so whatever that drawing leaves behind — a scale, a flipped axis — cannot move ours.)
// A line of the PDF's own text that was changed is first covered in its background colour, the new words
// then drawn over it; the old ones stay in the file beneath, no longer seen or printed.
import { PDFDocument, degrees, rgb } from 'pdf-lib';
import { pdfPlacement, pdfRectangle } from './geometry.js';
import { pngBytes } from './images.js';

export async function stampPdf(original, items, viewportOf) {
    const pdf = await PDFDocument.load(original, { updateMetadata: false });
    const images = new Map();

    // Every cover before any picture: one changed line's cover never hides another's new words.
    for (const item of items.filter((candidate) => candidate.cover)) {
        const [r, g, b] = [1, 3, 5].map((at) => parseInt(item.cover.color.slice(at, at + 2), 16) / 255);
        pdf.getPage(item.page).drawRectangle({ ...pdfRectangle(viewportOf(item.page), item.cover), color: rgb(r, g, b) });
    }

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
