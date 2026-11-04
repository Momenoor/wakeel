// "From OneDrive" for the PDF tools: the user's own OneDrive browsed and searched (through the page's Livewire
// methods, App\Filament\Mms\Pages\Concerns\PicksPdfsFromOneDrive), and a PDF fetched through the server, which
// alone may reach it. Added to a tool's Alpine component — withOneDrivePicker({ ... }), its getters kept getters —
// which says what a chosen PDF is for, and holds `config` and `labels`:
//
//   pickedFromOneDrive(item)      — a PDF chosen
//   pickedOneDriveFolder(folder)  — "Add every PDF in this folder" (only where the component sets pickFolders)
import { byName, fill } from './common.js';

export function withOneDrivePicker(component) {
    return Object.defineProperties(Object.defineProperties({}, Object.getOwnPropertyDescriptors(picker())), Object.getOwnPropertyDescriptors(component));
}

function picker() {
    return {
        pickerOpen: false,
        pickerLoading: false,
        pickerError: null,
        trail: [],
        items: [],
        search: '',
        pickFolders: false,

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
            await this.load(() => this.$wire.oneDriveFolder(this.currentFolder?.id ?? null));
        },

        get currentFolder() {
            return this.trail[this.trail.length - 1] ?? null;
        },

        // The PDFs of the folder open in the picker, in the order its names give.
        get folderPdfs() {
            return this.items.filter((item) => ! item.folder).sort((a, b) => byName(a.name, b.name));
        },

        async find() {
            const words = this.search.trim();

            if (words.length < 2) {
                return this.load(() => this.$wire.oneDriveFolder(this.currentFolder?.id ?? null));
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

            await this.pickedFromOneDrive(item);
        },

        // Fetched through the server, shown arriving (onProgress(percent) when the size is known).
        async fetchOneDrive(item, onProgress = () => {}) {
            const response = await fetch(this.config.oneDriveUrl.replace('__ID__', encodeURIComponent(item.id)), { credentials: 'same-origin' });

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
                    onProgress(Math.min(100, Math.round((received / total) * 100)));
                }
            }

            return new Blob(chunks, { type: 'application/pdf' });
        },

        downloadingStatus(percent) {
            return fill(this.labels.downloading, { percent: percent === null ? '' : `${percent}%` });
        },
    };
}
