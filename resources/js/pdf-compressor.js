// The Compress PDF page (App\Filament\Mms\Pages\CompressPdf): a PDF from the computer, the user's own OneDrive, or
// handed on from Merge PDF, made smaller in the browser (pdf-compressor/compress.js) at the level chosen, and the
// result downloaded or handed on to Split PDF. Nothing is uploaded. Loaded by Alpine (x-load) on that page only.
import { fill, megabytes, pdfName, save } from './pdf/common.js';
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
            result = null;
            signal = { cancelled: false };
            this.status = this.labels.reading;
            const startedAt = performance.now();

            try {
                const outcome = await compressPdf(new Uint8Array(await source.arrayBuffer()), this.level, {
                    signal,
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
