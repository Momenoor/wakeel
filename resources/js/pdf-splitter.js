// The Split PDF page (App\Filament\Mms\Pages\SplitPdf): a PDF from the computer, the user's own OneDrive, or handed
// on from Merge PDF or Compress PDF (pdf/handoff.js), split in the browser — by size or by pages
// (pdf-splitter/split.js) — and its parts downloaded in one zip (pdf-splitter/zip.js) or one by one. Loaded by
// Alpine (x-load) on that page only.
import { MB, fill, megabytes, numberFrom, save } from './pdf/common.js';
import { takeHandoff } from './pdf/handoff.js';
import { isPdf } from './pdf/is-pdf.js';
import { withOneDrivePicker } from './pdf/onedrive-picker.js';
import { Cancelled, Protected, analyse, loadPdf, splitByPages, splitBySize } from './pdf-splitter/split.js';
import { zipFiles } from './pdf-splitter/zip.js';

export { numberFrom };

export default function pdfSplitter(config) {
    // Kept out of Alpine's reach: pdf-lib's objects and big files have no business in its reactive proxies.
    let doc = null;
    let analysis = null;
    let signal = null;
    let zip = null;
    let blobs = [];

    return withOneDrivePicker({
        config,
        labels: config.labels,
        file: null,
        pageCount: 0,
        mode: 'size',
        maxMb: 5,
        perPart: 10,
        busy: false,
        status: '',
        percent: null,
        error: null,
        parts: [],
        warnings: [],
        zipName: '',
        // What was done, in words, once it is done.
        summary: '',

        // A PDF handed on from Merge PDF or Compress PDF.
        async init() {
            const handed = await takeHandoff();

            if (handed) {
                await this.open(handed.blob, handed.name, 'handoff');
            }
        },

        // ── The PDF ─────────────────────────────────────────────────────────

        async pick(event) {
            const file = event.target.files?.[0] ?? event.dataTransfer?.files?.[0];

            if (event.target.value !== undefined) {
                event.target.value = '';
            }

            if (file && ! this.busy) {
                await this.open(file, file.name, 'upload');
            }
        },

        async open(blob, name, source) {
            if (blob.size > config.maxBytes) {
                return this.fail(this.labels.tooBig);
            }

            this.reset();
            this.busy = true;
            this.status = this.labels.reading;

            try {
                const bytes = new Uint8Array(await blob.arrayBuffer());

                if (! isPdf(bytes)) {
                    return this.fail(this.labels.notPdf);
                }

                doc = await loadPdf(bytes);
                this.pageCount = doc.getPageCount();
                this.file = { name, size: blob.size, source };
            } catch (error) {
                doc = null;
                this.fail(error instanceof Protected ? this.labels.protected : this.labels.loadFailed);
            } finally {
                this.busy = false;
                this.status = '';
            }
        },

        // Everything about the last PDF forgotten: its document, its parts.
        reset() {
            doc = null;
            analysis = null;
            zip = null;
            blobs = [];
            this.file = null;
            this.pageCount = 0;
            this.parts = [];
            this.warnings = [];
            this.summary = '';
            this.error = null;
        },

        get baseName() {
            return (this.file?.name ?? '').replace(/\.pdf$/i, '');
        },

        // ── Splitting ───────────────────────────────────────────────────────

        async split() {
            const limit = numberFrom(this.maxMb);
            const perPart = numberFrom(this.perPart);

            if (this.mode === 'size' && ! (limit >= 0.5)) {
                return this.fail(this.labels.badLimit);
            }

            if (this.mode === 'pages' && ! (Number.isInteger(perPart) && perPart >= 1)) {
                return this.fail(this.labels.badPages);
            }

            this.busy = true;
            this.error = null;
            this.parts = [];
            this.warnings = [];
            this.summary = '';
            zip = null;
            const startedAt = performance.now();
            blobs = [];
            signal = { cancelled: false };

            const pages = this.pageCount;
            let part = 1;
            let parts = null;
            const progress = {
                signal,
                onPart: (number, total) => {
                    part = number;
                    parts = total;
                },
                onPage: (page) => {
                    this.status = fill(parts ? this.labels.progressOf : this.labels.progress, { part, parts, page, pages });
                    this.percent = Math.round((page / pages) * 100);
                },
            };

            try {
                // Once per document: what each page needs, and its links to other pages cut (see analyse()).
                analysis ??= await analyse(doc, {
                    signal,
                    onPage: (page) => {
                        this.status = fill(this.labels.preparing, { page, pages });
                        this.percent = Math.round((page / pages) * 100);
                    },
                });

                const made = this.mode === 'size'
                    ? await splitBySize(doc, analysis, limit * MB, progress)
                    : await splitByPages(doc, perPart, progress);

                this.status = this.labels.zipping;
                this.percent = null;
                const width = Math.max(2, String(made.length).length);
                const number = (n) => String(n).padStart(width, '0');

                blobs = made.map((piece) => piece.blob);
                this.parts = made.map((piece, index) => ({
                    name: `${this.baseName} - part ${number(index + 1)} of ${number(made.length)}.pdf`,
                    from: piece.from,
                    to: piece.to,
                    size: piece.size,
                    over: piece.over,
                }));
                this.warnings = this.parts.filter((piece) => piece.over)
                    .map((piece) => fill(this.labels.tooBigPage, { page: piece.from, size: megabytes(piece.size), limit }));

                this.zipName = `${this.baseName} - split.zip`;
                zip = await zipFiles([
                    ...this.parts.map((piece, index) => ({ name: piece.name, blob: blobs[index] })),
                    { name: 'parts.txt', text: this.listing() },
                ], signal);

                // Not downloaded by itself: it waits for "Download all (zip)", and each part for its own button.
                this.summary = this.describe(made, limit, perPart, Math.max(1, Math.round((performance.now() - startedAt) / 1000)));

                // Only who split what, and how: the file never leaves the browser.
                Promise.resolve(this.$wire.recordSplit(this.file.name, this.file.source, this.mode, this.mode === 'size' ? limit : perPart, this.parts.length, pages)).catch(() => {});
            } catch (error) {
                this.parts = [];
                this.warnings = [];
                blobs = [];

                if (! (error instanceof Cancelled)) {
                    console.error(error);
                    this.fail(this.labels.splitFailed);
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

        pagesOf(piece) {
            return piece.from === piece.to ? fill(this.labels.page, { from: piece.from }) : fill(this.labels.pages, { from: piece.from, to: piece.to });
        },

        sizeOf(bytes) {
            return fill(this.labels.mb, { size: megabytes(bytes) });
        },

        // What was done, in a sentence or two: the file, how it was split, into how many parts and how big.
        describe(made, limit, perPart, seconds) {
            const sizes = made.map((piece) => piece.size);
            const how = this.mode === 'size'
                ? fill(this.labels.howSize, { limit })
                : fill(this.labels.howPages, { count: perPart });

            return [
                fill(made.length === 1 ? this.labels.summaryOne : this.labels.summary, {
                    name: this.file.name,
                    pages: this.pageCount,
                    size: megabytes(this.file.size),
                    how,
                    parts: made.length,
                    seconds,
                }),
                fill(this.labels.summarySizes, { smallest: megabytes(Math.min(...sizes)), largest: megabytes(Math.max(...sizes)) }),
                this.labels.summaryNext,
            ].join(' ');
        },

        // parts.txt: which pages went into which part, and how big each is.
        listing() {
            const lines = this.parts.map((piece) => fill(this.labels.listLine, { name: piece.name, from: piece.from, to: piece.to, size: megabytes(piece.size) }));

            return [this.file.name, '', ...lines, ...(this.warnings.length ? ['', ...this.warnings] : [])].join('\r\n') + '\r\n';
        },

        downloadZip() {
            zip && save(zip, this.zipName);
        },

        downloadPart(index) {
            blobs[index] && save(blobs[index], this.parts[index].name);
        },

        // ── From OneDrive ───────────────────────────────────────────────────

        // Fetched through the server (it alone may reach the user's OneDrive), shown arriving.
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

        fail(message) {
            this.error = message;
        },
    });
}
