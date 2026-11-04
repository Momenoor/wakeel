// Any picture of a PDF drawn by pdf.js — JPEG 2000, CMYK, a palette, 16 bits, a Lab or ICC colour space, a
// Decode array, CCITT or JBIG2 — whatever the browser itself cannot read. The picture is copied alone (no soft
// mask) onto the one page of a small PDF the size of the drawing wanted, a point a pixel, and that page drawn on a
// canvas: pdf.js decodes it and scales it down as it would on screen. One worker serves every picture.
import { PDFDocument, PDFName, PDFObjectCopier, PDFRawStream } from 'pdf-lib';
import { PDFWorker } from 'pdfjs-dist';
import { loadDocument } from '../pdf-signer/render.js';

// What a picture brings with it that has no place on the page drawn: drawn opaque, its mask kept by the caller.
const LEFT_OUT = ['SMask', 'Mask', 'Metadata', 'Alternates', 'OPI', 'StructParent'].map((name) => PDFName.of(name));

export function imageDrawer() {
    let worker = null;

    return {
        // The picture as a canvas w × h on white, or throws.
        async draw(sourceContext, image, w, h) {
            const mini = await PDFDocument.create({ updateMetadata: false });
            const dict = image.dict.clone(sourceContext);
            LEFT_OUT.forEach((key) => dict.delete(key));

            const copy = PDFObjectCopier.for(sourceContext, mini.context).copy(PDFRawStream.of(dict, image.contents));
            const page = mini.addPage([w, h]);
            page.node.set(PDFName.of('Resources'), mini.context.obj({ XObject: { I: mini.context.register(copy) } }));
            page.node.set(PDFName.of('Contents'), mini.context.register(mini.context.flateStream(`q ${w} 0 0 ${h} 0 0 cm /I Do Q`)));

            worker ??= new PDFWorker();
            const pdf = await loadDocument(await mini.save({ useObjectStreams: false }), { worker });

            try {
                const drawn = await pdf.getPage(1);
                const canvas = Object.assign(document.createElement('canvas'), { width: w, height: h });
                await drawn.render({ canvas, viewport: drawn.getViewport({ scale: 1 }), intent: 'print', background: '#ffffff' }).promise;

                return canvas;
            } finally {
                await pdf.destroy();
            }
        },

        close() {
            worker?.destroy();
            worker = null;
        },
    };
}
