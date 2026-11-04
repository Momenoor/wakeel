// The Compress PDF page (App\Filament\Mms\Pages\CompressPdf): a PDF from the computer, the user's own OneDrive, or
// handed on from Merge PDF, made smaller in the browser (pdf-compressor/compress.js) at the level chosen, and the
// result downloaded or handed on to Split PDF. Nothing is uploaded. Loaded by Alpine (x-load) on that page only.
import { fill, megabytes, numberFrom, pdfName, save } from './pdf/common.js';
import { handOn, takeHandoff } from './pdf/handoff.js';
import { isPdf } from './pdf/is-pdf.js';
import { withOneDrivePicker } from './pdf/onedrive-picker.js';
import { compressPdf } from './pdf-compressor/compress.js';
import { Cancelled, Protected } from './pdf-splitter/split.js';

export default function pdfCompressor(config) {
    // Kept out of Alpine's reach: the PDF and what it became.
    let source = null;
    let result = null;
    let signal = null;
    // The preview's two documents, opened by pdf.js: the PDF before and after.
    let before = null;
    let after = null;
    let following = false;
    const previewer = () => import('./pdf-compressor/preview.js');

    return withOneDrivePicker({
        config,
        labels: config.labels,
        file: null,
        level: 'balanced',
        busy: false,
        status: '',
        percent: null,
        error: null,
        done: null,
        // A page of the PDF before and after, side by side: which, of how many, how large, and the number typed.
        preview: null,

        async init() {
            const handed = await takeHandoff();

            if (handed) {
                await this.open(handed.blob, handed.name, 'handoff');
            }
        },

        async pick(event) {
            const file = event.target.files?.[0] ?? event.dataTransfer?.files?.[0];

            if (event.target.value !== undefined) {
                event.target.value = '';
            }

            if (file && ! this.busy) {
                await this.open(file, file.name, 'upload');
            }
        },

        async open(blob, name, from) {
            if (blob.size > config.maxBytes) {
                return this.fail(this.labels.tooBig);
            }

            this.reset();
            const head = new Uint8Array(await blob.slice(0, 1024).arrayBuffer());

            if (! isPdf(head)) {
                return this.fail(this.labels.notPdf);
            }

            source = blob;
            this.file = { name, size: blob.size, source: from };
        },

        reset() {
            this.closePreview();
            source = null;
            result = null;
            this.file = null;
            this.done = null;
            this.error = null;
        },

        async compress() {
            if (! source) {
                return;
            }

            this.busy = true;
            this.error = null;
            this.done = null;
            this.closePreview();
            result = null;
            signal = { cancelled: false };
            this.status = this.labels.reading;
            const startedAt = performance.now();

            try {
                const outcome = await compressPdf(new Uint8Array(await source.arrayBuffer()), this.level, {
                    signal,
                    workerUrl: config.workerUrl,
                    onImage: (number, total) => {
                        this.status = fill(this.labels.progress, { number, total });
                        this.percent = Math.round((number / total) * 100);
                    },
                });

                result = outcome.blob;
                this.done = {
                    name: pdfName(`${this.file.name.replace(/\.pdf$/i, '')} - compressed`, this.labels.defaultName),
                    size: result.size,
                    pages: outcome.pages,
                    smaller: outcome.smaller,
                    saved: Math.max(0, Math.round((1 - result.size / this.file.size) * 100)),
                    seconds: Math.max(1, Math.round((performance.now() - startedAt) / 1000)),
                };

                Promise.resolve(this.$wire.recordCompress(this.file.name, this.file.source, this.level, this.file.size, result.size, outcome.pages)).catch(() => {});
            } catch (error) {
                if (! (error instanceof Cancelled)) {
                    console.error(error);
                    this.fail(error instanceof Protected ? this.labels.protected : this.labels.compressFailed);
                }
            } finally {
                this.busy = false;
                this.status = '';
                this.percent = null;
                signal = null;
            }

            if (this.done?.smaller) {
                this.openPreview();
            }
        },

        // ── The preview, before and after ───────────────────────────────────

        async openPreview() {
            const { openDocument } = await previewer();
            const compressed = result;
            let opened;

            try {
                opened = await Promise.all([openDocument(source), openDocument(compressed)]);
            } catch (error) {
                console.error(error);

                return this.closePreview();
            }

            // Discarded, or compressed again, while they opened: these two are no longer wanted.
            if (result !== compressed || this.busy) {
                return opened.forEach((doc) => doc.destroy());
            }

            [before, after] = opened;

            this.preview = { page: 1, pages: after.numPages, zoom: 1, typed: '1' };
            await this.$nextTick();
            await this.drawPreview();
        },

        async drawPreview() {
            if (! this.preview || ! before || ! after) {
                return;
            }

            const { drawPage } = await previewer();
            const { page, zoom } = this.preview;

            try {
                await Promise.all([drawPage(before, page, this.$refs.before, zoom), drawPage(after, page, this.$refs.after, zoom)]);
            } catch (error) {
                console.error(error);
            }
        },

        // To page `number` (as typed, in any digits), within the PDF's pages.
        goTo(number) {
            if (! this.preview) {
                return;
            }

            const wanted = typeof number === 'number' ? number : numberFrom(number);
            const page = Number.isFinite(wanted) ? Math.min(this.preview.pages, Math.max(1, Math.round(wanted))) : this.preview.page;

            this.preview.typed = String(page);

            if (page !== this.preview.page) {
                this.preview.page = page;
                this.drawPreview();
            }
        },

        zoomTo(zoom) {
            if (this.preview) {
                this.preview.zoom = Number(zoom) || 1;
                this.drawPreview();
            }
        },

        // Zoomed in, the two pages scroll together: the same part of the page seen in both.
        follow(event, other) {
            if (following) {
                return;
            }

            const from = event.target;
            const to = this.$refs[other];
            following = true;
            to.scrollTop = (from.scrollTop / Math.max(1, from.scrollHeight - from.clientHeight)) * (to.scrollHeight - to.clientHeight);
            to.scrollLeft = (from.scrollLeft / Math.max(1, from.scrollWidth - from.clientWidth)) * (to.scrollWidth - to.clientWidth);
            requestAnimationFrame(() => (following = false));
        },

        closePreview() {
            this.preview = null;
            before?.destroy();
            after?.destroy();
            before = null;
            after = null;
        },

        cancel() {
            if (signal) {
                signal.cancelled = true;
            }
        },

        get summary() {
            if (! this.done) {
                return '';
            }

            return this.done.smaller
                ? fill(this.labels.summary, { name: this.file.name, from: megabytes(this.file.size), to: megabytes(this.done.size), saved: this.done.saved, seconds: this.done.seconds })
                : fill(this.labels.summaryNone, { name: this.file.name, size: megabytes(this.file.size) });
        },

        download() {
            result && save(result, this.done.smaller ? this.done.name : this.file.name);
        },

        next(url) {
            result && url && handOn(result, this.done.smaller ? this.done.name : this.file.name, url);
        },

        // ── From OneDrive ───────────────────────────────────────────────────

        async pickedFromOneDrive(item) {
            this.pickerOpen = false;

            if (this.busy) {
                return;
            }

            this.reset();
            this.busy = true;
            this.status = this.downloadingStatus(null);

            let blob = null;

            try {
                blob = await this.fetchOneDrive(item, (percent) => {
                    this.percent = percent;
                    this.status = this.downloadingStatus(percent);
                });
            } catch {
                this.fail(this.labels.downloadFailed);
            } finally {
                this.busy = false;
                this.status = '';
                this.percent = null;
            }

            if (blob) {
                await this.open(blob, item.name, 'onedrive');
            }
        },

        sizeOf(bytes) {
            return fill(this.labels.mb, { size: megabytes(bytes) });
        },

        fail(message) {
            this.error = message;
        },
    });
}
