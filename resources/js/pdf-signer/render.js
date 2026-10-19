// Showing the PDF's pages with pdf.js. The worker, the decoders for scanned images (JBIG2, JPEG 2000,
// colour profiles) and the 14 standard fonts are files of the build, found by name through small
// factories — pdf.js would otherwise look for them in a folder of its own.
import { GlobalWorkerOptions, getDocument } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

GlobalWorkerOptions.workerSrc = workerUrl;

const wasmFiles = import.meta.glob('/node_modules/pdfjs-dist/wasm/*.wasm', { query: '?url', import: 'default', eager: true });
const fontFiles = import.meta.glob('/node_modules/pdfjs-dist/standard_fonts/*.{pfb,ttf}', { query: '?url', import: 'default', eager: true });

function factory(files) {
    const urls = Object.fromEntries(Object.entries(files).map(([path, url]) => [path.split('/').pop(), url]));

    return class {
        async fetch({ filename }) {
            if (! urls[filename]) {
                throw new Error(`Not part of the build: ${filename}`);
            }

            const response = await fetch(urls[filename]);

            if (! response.ok) {
                throw new Error(`Could not load ${filename}`);
            }

            return new Uint8Array(await response.arrayBuffer());
        }
    };
}

const WasmFactory = factory(wasmFiles);
const StandardFontDataFactory = factory(fontFiles);

// The document, each page, and each page's viewport at scale 1 — its display space in PDF points.
export async function openPdf(bytes) {
    const doc = await getDocument({
        // pdf.js hands the buffer to its worker: a copy, so the original stays ours for writing.
        data: bytes.slice(),
        WasmFactory,
        StandardFontDataFactory,
        isEvalSupported: false,
    }).promise;

    const pages = [];

    for (let number = 1; number <= doc.numPages; number++) {
        pages.push(await doc.getPage(number));
    }

    return { doc, pages, viewports: pages.map((page) => page.getViewport({ scale: 1 })) };
}

// Biggest canvas drawn, in pixels: a phone or a tablet runs out of memory long before a desktop.
const MAX_PIXELS = 16_000_000;

// A page drawn on a new canvas at this zoom (sharp on high-density screens): put it in place once ready.
export function renderPage(page, zoom) {
    const ratio = window.devicePixelRatio || 1;
    const base = page.getViewport({ scale: 1 });
    const scale = Math.min(zoom * ratio, Math.sqrt(MAX_PIXELS / (base.width * base.height)));
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');

    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    canvas.className = 'pointer-events-none absolute inset-0 h-full w-full';

    return { canvas, task: page.render({ canvas, viewport }) };
}

export { isPdf } from '../pdf/is-pdf.js';
