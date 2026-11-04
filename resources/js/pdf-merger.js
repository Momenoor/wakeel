// The Merge PDF page (App\Filament\Mms\Pages\MergePdf): PDFs chosen from the computer — files, or a whole folder —
// or from the user's own OneDrive — files, or every PDF in a folder — put in order (a folder's by its names, then as
// moved), merged in the browser (pdf-merger/merge.js), and the result downloaded or handed on to Compress PDF or
// Split PDF (pdf/handoff.js). Nothing is uploaded. Loaded by Alpine (x-load) on that page only.
import { byName, fill, megabytes, pdfName, save } from './pdf/common.js';
import { handOn } from './pdf/handoff.js';
import { isPdf } from './pdf/is-pdf.js';
import { withOneDrivePicker } from './pdf/onedrive-picker.js';
import { FileFailed, mergePdfs } from './pdf-merger/merge.js';
import { Cancelled, Protected } from './pdf-splitter/split.js';

let lastId = 0;

export default function pdfMerger(config) {
    // Kept out of Alpine's reach: the files themselves and the merged PDF.
    const blobs = new Map();
    let result = null;
    let signal = null;

    return withOneDrivePicker({
        config,
        labels: config.labels,
        pickFolders: true,
        // { id, name, size, source: 'upload' | 'onedrive', item? } in the order they will be merged.
        files: [],
        outputName: '',
        bookmarks: true,
        busy: false,
        status: '',
        percent: null,
        error: null,
        merged: null,

        // ── The files ───────────────────────────────────────────────────────

        // Files chosen or dropped, in the order given; a folder's in the order of its names (and sub-folders').
        pick(event, folder = false) {
            const chosen = [...(event.target.files ?? event.dataTransfer?.files ?? [])];

            if (event.target.value !== undefined) {
                event.target.value = '';
            }

            const pdfs = chosen.filter((file) => /\.pdf$/i.test(file.name) || file.type === 'application/pdf');

            if (folder) {
                pdfs.sort((a, b) => byName(a.webkitRelativePath || a.name, b.webkitRelativePath || b.name));

                if (! this.outputName && pdfs[0]?.webkitRelativePath) {
                    this.outputName = pdfs[0].webkitRelativePath.split('/')[0];
                }
            }

            if (chosen.length && ! pdfs.length) {
                return this.fail(this.labels.noPdfs);
            }

            pdfs.forEach((file) => this.add(file, file.name, 'upload'));
        },

        add(blob, name, source, item = null) {
            if ((blob?.size ?? item?.size ?? 0) > config.maxBytes) {
                return this.fail(fill(this.labels.fileTooBig, { name }));
            }

            const id = ++lastId;

            if (blob) {
                blobs.set(id, blob);
            }

            this.files.push({ id, name, size: blob?.size ?? item?.size ?? null, source, item });
            this.forgetResult();
        },

        remove(index) {
            blobs.delete(this.files[index].id);
            this.files.splice(index, 1);
            this.forgetResult();
        },

        move(index, by) {
            const to = index + by;

            if (to < 0 || to >= this.files.length) {
                return;
            }

            const [file] = this.files.splice(index, 1);
            this.files.splice(to, 0, file);
            this.forgetResult();
        },

        sortByName() {
            this.files.sort((a, b) => byName(a.name, b.name));
            this.forgetResult();
        },

        clear() {
            blobs.clear();
            this.files = [];
            this.outputName = '';
            this.forgetResult();
        },

        get totalSize() {
            return this.files.reduce((sum, file) => sum + (file.size ?? 0), 0);
        },

        // ── From OneDrive ───────────────────────────────────────────────────

        pickedFromOneDrive(item) {
            this.add(null, item.name, 'onedrive', item);
        },

        // Every PDF in the folder open in the picker, in the order of its names.
        pickedOneDriveFolder() {
            const pdfs = this.folderPdfs;

            if (! pdfs.length) {
                return this.fail(this.labels.noPdfs);
            }

            if (! this.outputName && this.currentFolder) {
                this.outputName = this.currentFolder.name;
            }

            pdfs.forEach((item) => this.add(null, item.name, 'onedrive', item));
            this.pickerOpen = false;
        },

        isAdded(item) {
            return this.files.some((file) => file.item?.id === item.id);
        },

        // ── Merging ─────────────────────────────────────────────────────────

        async merge() {
            if (this.files.length < 2) {
                return this.fail(this.labels.needTwo);
            }

            this.busy = true;
            this.error = null;
            this.forgetResult();
            signal = { cancelled: false };
            const startedAt = performance.now();

            const sources = this.files.map((file) => ({
                name: file.name,
                bytes: async () => {
                    let blob = blobs.get(file.id);

                    if (! blob) {
                        this.status = fill(this.labels.fetching, { name: file.name });
                        blob = await this.fetchOneDrive(file.item, (percent) => {
                            this.percent = percent;
                        });
                        blobs.set(file.id, blob);
                    }

                    const bytes = new Uint8Array(await blob.arrayBuffer());

                    if (! isPdf(bytes)) {
                        throw new Error('not a pdf');
                    }

                    return bytes;
                },
            }));

            try {
                const { blob, pages } = await mergePdfs(sources, {
                    bookmarks: this.bookmarks,
                    signal,
                    onFile: (number, total, name) => {
                        this.status = fill(this.labels.progress, { number, total, name });
                        this.percent = Math.round(((number - 1) / total) * 100);
                    },
                });

                result = blob;
                this.merged = {
                    name: pdfName(this.outputName, this.labels.defaultName),
                    pages,
                    size: blob.size,
                    files: this.files.length,
                    seconds: Math.max(1, Math.round((performance.now() - startedAt) / 1000)),
                };

                // Only who merged what: the files never leave the browser.
                Promise.resolve(this.$wire.recordMerge(this.merged.name, this.files.map((file) => file.name), this.files.some((file) => file.source === 'onedrive') ? 'onedrive' : 'upload', pages)).catch(() => {});
            } catch (error) {
                if (! (error instanceof Cancelled)) {
                    console.error(error);
                    this.fail(error instanceof FileFailed
                        ? fill(error.cause instanceof Protected ? this.labels.protected : this.labels.fileFailed, { name: error.file })
                        : this.labels.mergeFailed);
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
            return this.merged ? fill(this.labels.summary, { ...this.merged, size: megabytes(this.merged.size) }) : '';
        },

        download() {
            result && save(result, this.merged.name);
        },

        // On to the next tool, with the merged PDF.
        next(url) {
            result && url && handOn(result, this.merged.name, url);
        },

        forgetResult() {
            result = null;
            this.merged = null;
        },

        sizeOf(bytes) {
            return bytes === null || bytes === undefined ? '' : fill(this.labels.mb, { size: megabytes(bytes) });
        },

        fail(message) {
            this.error = message;
        },
    });
}
