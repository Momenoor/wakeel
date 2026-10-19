// The Split PDF page (App\Filament\Mms\Pages\SplitPdf): a PDF from the computer or the user's own OneDrive,
// split in the browser — by size or by pages (pdf-splitter/split.js) — and its parts downloaded in one zip
// (pdf-splitter/zip.js) or one by one. Loaded by Alpine (x-load) on that page only.
import { isPdf } from './pdf/is-pdf.js';
import { Cancelled, Protected, analyse, loadPdf, splitByPages, splitBySize } from './pdf-splitter/split.js';
import { zipFiles } from './pdf-splitter/zip.js';

// A megabyte as the size limits on upload pages are safest read: a million bytes (a part of "4.99 MB" here is
// under 5 MB however the page receiving it counts).
const MB = 1_000_000;

const fill = (text, values) => Object.entries(values).reduce((result, [key, value]) => result.replaceAll(`:${key}`, value), text);
const megabytes = (bytes) => (bytes / MB).toFixed(2);

function save(blob, name) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 60_000);
}

export default function pdfSplitter(config) {
    // Kept out of Alpine's reach: pdf-lib's objects and big files have no business in its reactive proxies.
    let doc = null;
    let analysis = null;
    let signal = null;
    let zip = null;
    let blobs = [];

    return {
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

        // The OneDrive picker.
        pickerOpen: false,
        pickerLoading: false,
        pickerError: null,
        trail: [],
        items: [],
        search: '',

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
            this.error = null;
        },

        get baseName() {
            return (this.file?.name ?? '').replace(/\.pdf$/i, '');
        },

        // ── Splitting ───────────────────────────────────────────────────────

        async split() {
            const limit = Number(this.maxMb);
            const perPart = Number(this.perPart);

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
            zip = null;
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

                save(zip, this.zipName);

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

        openPicker() {
            this.pickerOpen = true;

            if (! this.items.length && ! this.pickerError) {
                this.browse(null);
            }
        },

        // A folder of theirs (their OneDrive's top when none); the path to it is kept for the breadcrumbs.
        async browse(folder) {
            this.search = '';
            this.trail = folder ? [...this.trail, folder] : [];
            await this.load(() => this.$wire.oneDriveFolder(folder?.id ?? null));
        },

        async goTo(index) {
            this.search = '';
            this.trail = this.trail.slice(0, index + 1);
            const folder = this.trail[this.trail.length - 1];
            await this.load(() => this.$wire.oneDriveFolder(folder?.id ?? null));
        },

        async find() {
            const words = this.search.trim();

            if (words.length < 2) {
                const folder = this.trail[this.trail.length - 1];

                return this.load(() => this.$wire.oneDriveFolder(folder?.id ?? null));
            }

            await this.load(() => this.$wire.oneDriveSearch(words));
        },

        async load(read) {
            this.pickerLoading = true;
            this.pickerError = null;

            try {
                const { items, error } = await read();
                this.items = items ?? [];
                this.pickerError = error;
            } catch {
                this.items = [];
                this.pickerError = this.labels.downloadFailed;
            } finally {
                this.pickerLoading = false;
            }
        },

        async choose(item) {
            if (item.folder) {
                // A folder found by searching: its path starts again from it.
                if (this.search.trim().length >= 2) {
                    this.trail = [];
                }

                return this.browse({ id: item.id, name: item.name });
            }

            this.pickerOpen = false;
            await this.fetchFromOneDrive(item);
        },

        // Fetched through the server (it alone may reach the user's OneDrive), shown arriving.
        async fetchFromOneDrive(item) {
            if (this.busy) {
                return;
            }

            this.reset();
            this.busy = true;
            this.status = fill(this.labels.downloading, { percent: '' });

            let blob = null;

            try {
                const response = await fetch(config.oneDriveUrl.replace('__ID__', encodeURIComponent(item.id)), { credentials: 'same-origin' });

                if (! response.ok || ! response.body) {
                    throw new Error(String(response.status));
                }

                const total = Number(response.headers.get('X-File-Size')) || item.size || 0;
                const reader = response.body.getReader();
                const chunks = [];
                let received = 0;

                for (let read = await reader.read(); ! read.done; read = await reader.read()) {
                    chunks.push(read.value);
                    received += read.value.length;

                    if (total) {
                        this.percent = Math.min(100, Math.round((received / total) * 100));
                        this.status = fill(this.labels.downloading, { percent: `${this.percent}%` });
                    }
                }

                blob = new Blob(chunks, { type: 'application/pdf' });
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
    };
}
