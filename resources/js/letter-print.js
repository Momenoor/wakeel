// A letter (or minutes) printed at its real size, in any browser. A browser's PDF viewer prints a PDF
// shrunk to the printer's printable area (Chrome and Edge start at about 95%; Firefox ignores the PDF's
// own "don't scale" setting). So the PDF's pages are drawn here, each as a picture exactly the page's size
// in millimetres, on a page of that size with no margins: printed as it is, at 100%.
import { renderPage, openPdf } from './pdf-signer/render.js';

// 300 dpi: a page's points (1/72 in) drawn at 300 pixels an inch.
const PRINT_ZOOM = 300 / 72;
const MM_PER_POINT = 25.4 / 72;

async function main() {
    const root = document.getElementById('letter-print');
    const status = document.getElementById('letter-print-status');

    try {
        const response = await fetch(root.dataset.pdf, { credentials: 'same-origin' });

        if (! response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const { pages, viewports } = await openPdf(new Uint8Array(await response.arrayBuffer()));

        // The paper: the first page's size (A4, or A4 landscape), with no margins of the browser's.
        const width = (viewports[0].width * MM_PER_POINT).toFixed(2);
        const height = (viewports[0].height * MM_PER_POINT).toFixed(2);
        const style = document.createElement('style');
        style.textContent = `@page { size: ${width}mm ${height}mm; margin: 0; }`;
        document.head.appendChild(style);

        for (const [index, page] of pages.entries()) {
            // Drawn as for print: sharp, and without waiting for the screen.
            const { canvas, task } = renderPage(page, PRINT_ZOOM / (window.devicePixelRatio || 1), 'print');
            await task.promise;

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
            const image = new Image();
            image.src = URL.createObjectURL(blob);
            image.alt = '';
            image.className = 'letter-page';
            image.style.width = `${(viewports[index].width * MM_PER_POINT).toFixed(2)}mm`;
            image.style.height = `${(viewports[index].height * MM_PER_POINT).toFixed(2)}mm`;
            await image.decode();
            root.appendChild(image);
        }

        status.remove();
        window.print();
    } catch (error) {
        status.textContent = status.dataset.failed;
        console.error(error);
    }
}

main();
