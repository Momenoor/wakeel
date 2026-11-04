// Compress PDF's preview: a page of the PDF before and after, drawn by pdf.js side by side, so what was saved can be
// checked by eye before it is downloaded. Loaded only once a PDF has been compressed.
import { loadDocument } from '../pdf-signer/render.js';

// Biggest canvas drawn, in pixels: zoomed in on a big page, a phone or a tablet runs out of memory first.
const MAX_PIXELS = 16_000_000;

export async function openDocument(blob) {
    return loadDocument(new Uint8Array(await blob.arrayBuffer()));
}

// Page `number` of `doc` drawn into `holder`, `zoom` times the holder's width; a drawing still on the way there
// (another page asked for meanwhile) is given up.
export async function drawPage(doc, number, holder, zoom) {
    holder.drawing?.cancel();

    const page = await doc.getPage(number);
    const base = page.getViewport({ scale: 1 });
    const width = Math.max(1, holder.clientWidth - 2) * zoom;
    const ratio = window.devicePixelRatio || 1;
    const scale = Math.min((width / base.width) * ratio, Math.sqrt(MAX_PIXELS / (base.width * base.height)));
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');

    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    canvas.style.width = `${width}px`;
    canvas.className = 'block max-w-none bg-white';

    const drawing = page.render({ canvas, viewport });
    holder.drawing = drawing;

    try {
        await drawing.promise;
        holder.replaceChildren(canvas);
    } catch (error) {
        if (error?.name !== 'RenderingCancelledException') {
            throw error;
        }
    } finally {
        if (holder.drawing === drawing) {
            holder.drawing = null;
        }
    }
}
