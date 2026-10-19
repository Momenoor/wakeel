// The Sign PDF page (App\Filament\Mms\Pages\SignPdf): a PDF shown page by page, signatures, stamps, a date
// and text put on it by tap or drag, moved, resized and turned, then written into the PDF in the browser.
// Loaded by Alpine (x-load) on that page only.
//
//   pdf.js  shows the pages            (pdf-signer/render.js)
//   pdf-lib writes the pictures in      (pdf-signer/embed.js)
//   items   are kept in each page's display space, in points (pdf-signer/geometry.js)
import { createPad, fitPad, trimmedDataUrl, undoStroke } from './pdf-signer/drawing.js';
import { stampPdf } from './pdf-signer/embed.js';
import { center, clamp, keepOnPage, mmToPoints, resized, rotated } from './pdf-signer/geometry.js';
import { loadImage, textImage } from './pdf-signer/images.js';
import { isPdf, openPdf, renderPage } from './pdf-signer/render.js';

const MIN_ZOOM = 0.3;
const MAX_ZOOM = 4;
// A text box's line, in points (12 pt type).
const TEXT_LINE = 16;

export default function pdfSigner(config) {
    // Kept out of Alpine's reach: pdf.js objects stop working inside its reactive proxies.
    let original = null;
    let pdf = null;
    let observer = null;
    let pad = null;
    let gesture = null;
    let nextId = 1;
    let nextKey = 1;
    const visible = new Set();
    const rendered = new Map();
    const listeners = [];

    const listen = (target, type, handler, options) => {
        target.addEventListener(type, handler, options);
        listeners.push(() => target.removeEventListener(type, handler, options));
    };

    const keyed = (stamp) => ({ ...stamp, key: `${stamp.id ?? 'drawn'}-${nextKey++}` });

    return {
        labels: config.labels,
        stamps: config.stamps.map(keyed),
        fileName: config.fileName || '',
        pages: [],
        items: [],
        history: [],
        selectedId: null,
        // What the next tap on a page puts there.
        armed: null,
        zoom: 1,
        loading: false,
        busy: false,
        dirty: false,
        error: null,
        dragGhost: null,
        drawOpen: false,
        drawEmpty: true,
        drawKeep: false,
        drawName: '',
        drawKind: 'signature',
        drawColor: '#0b1f66',

        async init() {
            listen(window, 'keydown', (event) => this.keydown(event));
            listen(window, 'beforeunload', (event) => {
                if (this.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });

            if (config.pdfUrl) {
                await this.loadFromUrl(config.pdfUrl);
            }
        },

        destroy() {
            listeners.forEach((remove) => remove());
            this.closeDocument();
            pad?.off();
        },

        get selected() {
            return this.items.find((item) => item.id === this.selectedId) ?? null;
        },

        get hasPdf() {
            return this.pages.length > 0;
        },

        // ── Opening ─────────────────────────────────────────────────────────

        async pick(event) {
            const file = event.target.files?.[0] ?? event.dataTransfer?.files?.[0];

            if (event.target.value !== undefined) {
                event.target.value = '';
            }

            if (! file) {
                return;
            }

            if (file.size > config.maxBytes) {
                return this.fail(this.labels.tooBig);
            }

            const bytes = new Uint8Array(await file.arrayBuffer());

            if (! isPdf(bytes)) {
                return this.fail(this.labels.notPdf);
            }

            this.fileName = file.name;
            await this.open(bytes);
        },

        async loadFromUrl(url) {
            this.loading = true;

            try {
                const response = await fetch(url, { credentials: 'same-origin' });

                if (! response.ok) {
                    throw new Error(String(response.status));
                }

                await this.open(new Uint8Array(await response.arrayBuffer()));
            } catch {
                this.fail(this.labels.loadFailed);
            } finally {
                this.loading = false;
            }
        },

        async open(bytes) {
            this.loading = true;
            this.error = null;

            try {
                this.closeDocument();
                pdf = await openPdf(bytes);
                original = bytes;
                this.items = [];
                this.history = [];
                this.selectedId = null;
                this.dirty = false;
                this.pages = pdf.viewports.map((viewport, index) => ({ index, w: viewport.width, h: viewport.height }));
                await this.$nextTick();
                this.fitWidth();
            } catch (error) {
                this.pages = [];
                this.fail(error?.name === 'PasswordException' ? this.labels.protected : this.labels.loadFailed);
            } finally {
                this.loading = false;
            }
        },

        closeDocument() {
            observer?.disconnect();
            observer = null;
            visible.clear();
            rendered.forEach(({ task }) => task.cancel());
            rendered.clear();
            pdf?.doc.destroy();
            pdf = null;
        },

        // ── Showing the pages ──────────────────────────────────────────────

        watchPage(element) {
            observer ??= new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    const index = Number(entry.target.dataset.page);
                    entry.isIntersecting ? visible.add(index) : visible.delete(index);
                });
                this.renderVisible();
            }, { root: this.$refs.viewer, rootMargin: '600px 0px' });

            observer.observe(element);
        },

        // The pages in view (and near it) drawn at the current zoom; each new drawing replaces the old one
        // when ready, so nothing flickers while zooming.
        renderVisible() {
            if (! pdf) {
                return;
            }

            for (const index of visible) {
                const done = rendered.get(index);

                if (done?.zoom === this.zoom) {
                    continue;
                }

                done?.task.cancel();
                const { canvas, task } = renderPage(pdf.pages[index], this.zoom);
                rendered.set(index, { task, zoom: this.zoom });

                task.promise
                    .then(() => this.$refs.viewer.querySelector(`[data-page="${index}"] canvas`)?.replaceWith(canvas))
                    .catch(() => {
                        if (rendered.get(index)?.task === task) {
                            rendered.delete(index);
                        }
                    });
            }
        },

        setZoom(zoom) {
            const viewer = this.$refs.viewer;
            const ratio = viewer.scrollHeight ? viewer.scrollTop / viewer.scrollHeight : 0;

            this.zoom = Math.round(clamp(zoom, MIN_ZOOM, MAX_ZOOM) * 100) / 100;

            this.$nextTick(() => {
                viewer.scrollTop = ratio * viewer.scrollHeight;
                this.renderVisible();
            });
        },

        fitWidth() {
            const widest = Math.max(...this.pages.map((page) => page.w), 1);
            this.setZoom((this.$refs.viewer.clientWidth - 40) / widest);
        },

        pageStyle(page) {
            return `width: ${page.w * this.zoom}px; height: ${page.h * this.zoom}px;`;
        },

        itemStyle(item) {
            const z = this.zoom;

            return `left: ${item.x * z}px; top: ${item.y * z}px; width: ${item.w * z}px; height: ${item.h * z}px; transform: rotate(${item.rot}deg);`;
        },

        itemsOn(index) {
            return this.items.filter((item) => item.page === index);
        },

        ghostStyle() {
            const ghost = this.dragGhost;

            return ghost ? `left: ${ghost.x - ghost.w / 2}px; top: ${ghost.y - ghost.h / 2}px; width: ${ghost.w}px; height: ${ghost.h}px;` : '';
        },

        // ── What can be put on a page ──────────────────────────────────────

        async templateFor(stamp) {
            const image = await loadImage(stamp.url);
            const w = mmToPoints(stamp.widthMm || config.widths[stamp.kind] || 40);

            return { kind: 'image', src: stamp.url, stampId: stamp.id ?? null, w, h: w * image.naturalHeight / image.naturalWidth };
        },

        textTemplate(text) {
            const image = textImage(text, { color: '#111827' });
            const h = TEXT_LINE * image.lines;

            return { kind: 'text', text, color: '#111827', src: image.src, lines: image.lines, w: h * image.aspect, h, stampId: null };
        },

        dateTemplate() {
            return this.textTemplate(config.today);
        },

        // From the library: a tap arms it (the next tap on a page puts it there), a drag drops it where the
        // finger or the mouse lets go.
        startLibraryDrag(event, makeTemplate) {
            if (event.button !== 0 || ! this.hasPdf) {
                return;
            }

            const start = { x: event.clientX, y: event.clientY };
            const template = Promise.resolve(makeTemplate()).catch(() => null);
            let dragging = false;

            const move = (moveEvent) => {
                if (! dragging && Math.hypot(moveEvent.clientX - start.x, moveEvent.clientY - start.y) < 6) {
                    return;
                }

                dragging = true;
                moveEvent.preventDefault();
                template.then((item) => {
                    if (item && dragging) {
                        this.dragGhost = { src: item.src, x: moveEvent.clientX, y: moveEvent.clientY, w: item.w * this.zoom, h: item.h * this.zoom };
                    }
                });
            };

            const stop = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', stop);
                this.dragGhost = null;
            };

            const up = async (upEvent) => {
                const wasDragging = dragging;
                dragging = false;
                stop();
                const item = await template;

                if (! item) {
                    return this.fail(this.labels.imageFailed);
                }

                if (! wasDragging) {
                    this.armed = item;
                    this.selectedId = null;

                    return;
                }

                const target = this.pageAt(upEvent.clientX, upEvent.clientY);

                if (target) {
                    this.place(item, target.index, this.pointIn(target.element, upEvent));
                }
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', stop);
        },

        place(template, index, point) {
            this.remember();

            const item = keepOnPage({ ...template, id: nextId++, page: index, rot: 0, x: point.x - template.w / 2, y: point.y - template.h / 2 }, this.pages[index]);

            this.items.push(item);
            this.selectedId = item.id;
            this.armed = null;

            if (item.kind === 'text') {
                this.$nextTick(() => this.$refs.text?.focus());
            }
        },

        pageClick(event, index) {
            if (this.armed) {
                this.place(this.armed, index, this.pointIn(event.currentTarget, event));
            } else {
                this.selectedId = null;
            }
        },

        pageAt(x, y) {
            const element = document.elementFromPoint(x, y)?.closest('[data-page]');

            return element && this.$refs.viewer.contains(element) ? { index: Number(element.dataset.page), element } : null;
        },

        pointIn(element, event) {
            const box = element.getBoundingClientRect();

            return { x: (event.clientX - box.left) / this.zoom, y: (event.clientY - box.top) / this.zoom };
        },

        // ── Moving, resizing, turning ──────────────────────────────────────

        startGesture(event, item, mode) {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.selectedId = item.id;
            this.armed = null;

            const pageElement = event.currentTarget.closest('[data-page]');
            gesture = { mode, id: item.id, pageElement, start: { ...item }, from: this.pointIn(pageElement, event), before: this.snapshot(), changed: false };

            const move = (moveEvent) => this.moveGesture(moveEvent);
            const up = (upEvent) => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
                this.endGesture(upEvent);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
        },

        moveGesture(event) {
            const item = gesture && this.items.find((candidate) => candidate.id === gesture.id);

            if (! item) {
                return;
            }

            const point = this.pointIn(gesture.pageElement, event);
            const { start, from } = gesture;

            if (! gesture.changed) {
                if (Math.hypot(point.x - from.x, point.y - from.y) * this.zoom < 3) {
                    return;
                }

                this.remember(gesture.before);
                gesture.changed = true;
            }

            if (gesture.mode === 'move') {
                item.x = start.x + point.x - from.x;
                item.y = start.y + point.y - from.y;
            } else if (gesture.mode === 'resize') {
                Object.assign(item, resized(start, from, point));
            } else {
                item.rot = rotated(start, from, point);
            }
        },

        endGesture(event) {
            const item = gesture?.changed && this.items.find((candidate) => candidate.id === gesture.id);

            if (item && gesture.mode === 'move') {
                // Let go over another page: it moves there.
                const target = this.pageAt(event.clientX, event.clientY);

                if (target && target.index !== item.page) {
                    const point = this.pointIn(target.element, event);
                    item.page = target.index;
                    item.x = point.x - (gesture.from.x - gesture.start.x);
                    item.y = point.y - (gesture.from.y - gesture.start.y);
                }
            }

            if (item) {
                keepOnPage(item, this.pages[item.page]);
            }

            gesture = null;
        },

        rotateSelected(degrees) {
            const item = this.selected;

            if (item) {
                this.remember();
                item.rot = (((item.rot + degrees) % 360) + 360) % 360;
            }
        },

        // The selected item on every other page, at the same place relative to each page.
        everyPage() {
            const item = this.selected;

            if (! item || this.pages.length < 2) {
                return;
            }

            this.remember();
            const from = this.pages[item.page];
            const { x, y } = center(item);

            this.pages.filter((page) => page.index !== item.page).forEach((page) => {
                this.items.push(keepOnPage({ ...item, id: nextId++, page: page.index, x: x / from.w * page.w - item.w / 2, y: y / from.h * page.h - item.h / 2 }, page));
            });
        },

        remove(id = this.selectedId) {
            if (id === null) {
                return;
            }

            this.remember();
            this.items = this.items.filter((item) => item.id !== id);
            this.selectedId = null;
        },

        // ── Text boxes ─────────────────────────────────────────────────────

        // Redrawn as typed: the line height stays, the width follows the text, the centre does not move.
        updateText(value, color = null) {
            const item = this.selected;

            if (! item || item.kind !== 'text') {
                return;
            }

            const lineHeight = item.h / item.lines;
            const image = textImage(value, { color: color ?? item.color });
            const { x, y } = center(item);

            item.text = value;
            item.color = color ?? item.color;
            item.src = image.src;
            item.lines = image.lines;
            item.h = lineHeight * image.lines;
            item.w = item.h * image.aspect;
            item.x = x - item.w / 2;
            item.y = y - item.h / 2;
            this.dirty = true;
        },

        // ── Undo ───────────────────────────────────────────────────────────

        snapshot() {
            return this.items.map((item) => ({ ...item }));
        },

        remember(snapshot = this.snapshot()) {
            this.history.push(snapshot);
            this.history.splice(0, Math.max(0, this.history.length - 100));
            this.dirty = true;
        },

        undo() {
            const previous = this.history.pop();

            if (previous) {
                this.items = previous;

                if (! this.selected) {
                    this.selectedId = null;
                }
            }
        },

        keydown(event) {
            if (event.target.closest?.('input, textarea, select, [contenteditable]')) {
                return;
            }

            if ((event.key === 'Delete' || event.key === 'Backspace') && this.selected) {
                event.preventDefault();
                this.remove();
            } else if (event.key === 'Escape') {
                this.armed = null;
                this.selectedId = null;
                this.drawOpen = false;
            } else if (event.key.toLowerCase() === 'z' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                this.undo();
            }
        },

        // ── Draw now ───────────────────────────────────────────────────────

        openDraw() {
            this.drawOpen = true;

            this.$nextTick(() => {
                const canvas = this.$refs.drawCanvas;

                if (! pad) {
                    pad = createPad(canvas, { penColor: this.drawColor });
                    pad.addEventListener('endStroke', () => (this.drawEmpty = pad.isEmpty()));
                }

                fitPad(pad, canvas);
                // Once more after the window has been drawn (a phone lays it out a frame later).
                requestAnimationFrame(() => fitPad(pad, canvas));
            });
        },

        setDrawColor(color) {
            this.drawColor = color;

            if (pad) {
                pad.penColor = color;
            }
        },

        undoDrawing() {
            undoStroke(pad);
            this.drawEmpty = pad.isEmpty();
        },

        clearDrawing() {
            pad?.clear();
            this.drawEmpty = true;
        },

        // The drawing, ready to be put on a page — and kept in the library when asked.
        async useDrawing() {
            const src = trimmedDataUrl(this.$refs.drawCanvas);

            if (! src) {
                return;
            }

            this.busy = true;
            let stamp = { id: null, name: this.labels.drawing, kind: this.drawKind, url: src, widthMm: config.widths[this.drawKind] };

            try {
                if (this.drawKeep && config.canSaveDrawn) {
                    stamp = (await this.$wire.saveDrawn(src, this.drawName || this.labels.drawing, this.drawKind)) || stamp;
                }

                this.stamps.unshift(keyed(stamp));
                this.armed = await this.templateFor(stamp);
                this.clearDrawing();
                this.drawOpen = false;
                this.drawKeep = false;
                this.drawName = '';
            } catch {
                this.fail(this.labels.imageFailed);
            } finally {
                this.busy = false;
            }
        },

        // ── Saving ─────────────────────────────────────────────────────────

        signedName() {
            return `${(this.fileName || 'document').replace(/\.pdf$/i, '')} - signed.pdf`;
        },

        usedStampIds() {
            return [...new Set(this.items.map((item) => item.stampId).filter(Boolean))];
        },

        async signedBytes() {
            try {
                return await stampPdf(original, this.snapshot(), (index) => pdf.viewports[index]);
            } catch (error) {
                // pdf-lib's own error for a protected file is known by its message (its class cannot be
                // told apart: compiled for old browsers, it is a plain Error).
                throw new Error(/encrypted/i.test(error?.message ?? '') ? this.labels.protected : this.labels.saveFailed);
            }
        },

        // Made here and saved by the browser: the PDF itself never reaches the server.
        async download() {
            if (! this.items.length) {
                return this.fail(this.labels.nothingPlaced);
            }

            this.busy = true;
            this.error = null;

            try {
                const bytes = await this.signedBytes();
                const link = document.createElement('a');
                link.href = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
                link.download = this.signedName();
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(link.href), 60_000);
                this.dirty = false;

                // Only who signed what, and with which of their signatures.
                Promise.resolve(this.$wire.recordSigning(this.fileName, this.pages.length, this.usedStampIds())).catch(() => {});
            } catch (error) {
                this.fail(error.message);
            } finally {
                this.busy = false;
            }
        },

        // The signed copy kept beside the attachment it was made from; then back where the signer came from.
        async saveCopy() {
            if (! this.items.length) {
                return this.fail(this.labels.nothingPlaced);
            }

            this.busy = true;
            this.error = null;

            try {
                const bytes = await this.signedBytes();

                if (bytes.length > config.maxBytes) {
                    throw new Error(this.labels.tooBig);
                }

                const form = new FormData();
                form.append('file', new Blob([bytes], { type: 'application/pdf' }), this.signedName());
                form.append('pages', String(this.pages.length));
                this.usedStampIds().forEach((id) => form.append('items[]', String(id)));

                const response = await fetch(config.saveUrl, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': config.csrf, Accept: 'application/json' },
                });

                if (! response.ok) {
                    const body = await response.json().catch(() => null);
                    throw new Error(body?.errors?.file?.[0] ?? body?.message ?? this.labels.saveFailed);
                }

                this.dirty = false;
                window.location.href = config.returnUrl;
            } catch (error) {
                this.fail(error.message || this.labels.saveFailed);
                this.busy = false;
            }
        },

        fail(message) {
            this.error = message;
        },
    };
}
