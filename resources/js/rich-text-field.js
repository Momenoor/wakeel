// The rich text field (App\Filament\Forms\Components\RichText): TinyMCE, self-hosted under
// public/vendor/tinymce and loaded only on a page that has an editor. The field's config comes from
// PHP; what changes while the form is open (the placeholders a template's own fields add) is read
// from the field's data element each time a menu opens, so the editor never has to start again.

const BLOCK = 'div[data-type="customBlock"]';

let loading = null;

// One copy of TinyMCE for every editor on the page, loaded the first time one is drawn.
function loadTinyMce(baseUrl) {
    if (window.tinymce) {
        return Promise.resolve(window.tinymce);
    }

    loading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `${baseUrl}/tinymce.min.js`;
        script.onload = () => resolve(window.tinymce);
        script.onerror = () => {
            loading = null;
            reject(new Error('TinyMCE could not be loaded'));
        };
        document.head.appendChild(script);
    });

    return loading;
}

const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const decode = (html) => new DOMParser().parseFromString(`<!doctype html><body>${html}`, 'text/html').body.textContent;

function parseConfig(value) {
    try {
        const config = JSON.parse(value || '{}');

        return config && typeof config === 'object' ? config : {};
    } catch {
        return {};
    }
}

// Word puts lists as paragraphs with a typed bullet or number (mso-list) and its own markup around
// everything. TinyMCE's free paste keeps that markup, so it is cleaned here: Word's lists become
// real lists, and its classes, conditional comments and mso- styles go; the formatting (fonts,
// sizes, colours, alignment, indents) stays.
export function cleanWordHtml(html) {
    if (!/class=["']?Mso|xmlns:[wo]=|<o:p|mso-/i.test(html)) {
        return html;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');

    wordLists(doc);

    doc.querySelectorAll('o\\:p, xml, style, meta, link, title').forEach((node) => node.remove());

    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_COMMENT);
    const comments = [];
    while (walker.nextNode()) {
        comments.push(walker.currentNode);
    }
    comments.forEach((comment) => comment.remove());

    doc.body.querySelectorAll('*').forEach((element) => {
        if (element.tagName.includes(':')) {
            element.replaceWith(...element.childNodes);

            return;
        }

        element.removeAttribute('class');
        element.removeAttribute('lang');

        const style = element.getAttribute('style');
        if (style !== null) {
            const kept = style
                .split(';')
                .map((part) => part.trim())
                .filter((part) => part && !/^(mso-|tab-stops|font-variant-ligatures|text-autospace|layout-grid|page:|punctuation-wrap)/i.test(part))
                .join('; ');

            kept ? element.setAttribute('style', kept) : element.removeAttribute('style');
        }
    });

    // Spans left with nothing to say.
    doc.body.querySelectorAll('span:not([style]):not([dir])').forEach((span) => span.replaceWith(...span.childNodes));

    return doc.body.innerHTML;
}

function wordLists(doc) {
    // Word's list (l0, l1…) and the level in it.
    const level = (p) => {
        const match = /mso-list:\s*(l\d+)\s+level(\d+)/i.exec(p.getAttribute('style') || '');

        return match ? [match[1], Number(match[2])] : [null, 0];
    };

    // The typed bullet or number: between Word's [if !supportLists] and [endif].
    const marker = (p) => {
        let text = '';
        let inside = false;
        [...p.childNodes].forEach((node) => {
            if (node.nodeType === Node.COMMENT_NODE && /\[if !supportLists\]/i.test(node.data)) {
                inside = true;
                node.remove();
            } else if (node.nodeType === Node.COMMENT_NODE && /\[endif\]/i.test(node.data) && inside) {
                inside = false;
                node.remove();
            } else if (inside) {
                text += node.textContent;
                node.remove();
            }
        });

        return text.replace(/ /g, ' ').trim();
    };

    let stack = [];
    let current = null;

    [...doc.body.children].forEach((node) => {
        const [list, depth] = node.tagName === 'P' ? level(node) : [null, 0];

        // Another of Word's lists starts a list of its own.
        if (!depth || list !== current) {
            stack = [];
        }
        current = list;

        if (!depth) {
            return;
        }

        const ordered = /^[([]?[0-9a-zA-Z٠-٩]{1,4}[.)\]]$/.test(marker(node));

        while (stack.length > depth) {
            stack.pop();
        }

        while (stack.length < depth) {
            const list = doc.createElement(ordered ? 'ol' : 'ul');
            const parent = stack.at(-1);
            if (parent) {
                (parent.lastElementChild ?? parent.appendChild(doc.createElement('li'))).appendChild(list);
            } else {
                node.before(list);
            }
            stack.push(list);
        }

        const item = doc.createElement('li');
        item.append(...node.childNodes);
        const style = (node.getAttribute('style') || '').split(';').filter((part) => !/^\s*(margin|text-indent|mso-list)/i.test(part)).join(';');
        if (style.trim()) {
            item.setAttribute('style', style);
        }
        stack.at(-1).appendChild(item);
        node.remove();
    });
}

// The editor's own additions: the placeholders menu, typing {{ to pick one, and the signature blocks.
function registerPlugin(tinymce) {
    if (tinymce.PluginManager.get('wakeel')) {
        return;
    }

    tinymce.PluginManager.add('wakeel', (editor) => {
        editor.options.register('wakeel', { processor: 'object', default: {} });

        const options = () => editor.options.get('wakeel') || {};
        const t = (key) => options().labels?.[key] ?? key;
        const placeholders = () => (typeof options().placeholders === 'function' ? options().placeholders() : []) || [];

        const insertTag = (key) => editor.insertContent(escapeHtml(`{{${key}}}`));

        // A placeholder is one piece; double-clicked, what it says is changed (a condition's wording).
        const editTag = (node) => editor.windowManager.open({
            title: t('Edit placeholder'),
            body: { type: 'panel', items: [{ type: 'input', name: 'text', label: t('Placeholder') }] },
            initialData: { text: decode(node.getAttribute('data-mce-content') || '') },
            buttons: [
                { type: 'cancel', text: 'Cancel' },
                { type: 'submit', text: 'Save', primary: true },
            ],
            onSubmit: (api) => {
                const text = api.getData().text.trim();
                editor.selection.select(node);
                // Emptied: taken out.
                editor.insertContent(text === '' ? '' : escapeHtml(text));
                api.close();
            },
        });

        editor.ui.registry.addMenuButton('wkplaceholders', {
            text: '{ }',
            tooltip: t('Placeholders'),
            fetch: (callback) => {
                const items = placeholders().map((tag) => ({ type: 'menuitem', text: `${tag.label}  ·  {{${tag.key}}}`, onAction: () => insertTag(tag.key) }));

                callback(items.length ? items : [{ type: 'menuitem', text: t('No placeholders'), enabled: false }]);
            },
        });

        // {{ followed by a few letters of the name or the label.
        editor.ui.registry.addAutocompleter('wkplaceholders', {
            trigger: '{{',
            minChars: 0,
            columns: 1,
            fetch: (pattern) => {
                const query = String(pattern || '').toLowerCase();

                return Promise.resolve(placeholders()
                    .filter((tag) => tag.key.toLowerCase().includes(query) || String(tag.label).toLowerCase().includes(query))
                    .slice(0, 60)
                    .map((tag) => ({ type: 'autocompleteitem', value: tag.key, text: `${tag.label}  ·  {{${tag.key}}}` })));
            },
            onAction: (api, range, value) => {
                editor.selection.setRng(range);
                insertTag(value);
                api.hide();
            },
        });

        const blocks = () => options().blocks;

        if (!blocks()) {
            return;
        }

        const layoutName = (id) => (blocks().layouts || []).find((layout) => String(layout.id) === String(id))?.name;

        const label = (id, config) => {
            if (id === blocks().saved) {
                const name = layoutName(config.layout_id) ?? config.snapshot?.name;

                return name ? `${t('Saved signature block')}: ${name}` : t('Saved signature block');
            }

            if (id === blocks().signature) {
                const parts = [
                    config.title,
                    { matter: t("The matter's expert"), custom: config.name, none: null }[config.expert ?? 'matter'],
                    config.signature === false ? null : t('Signature'),
                    config.stamp === false ? null : t('Stamp'),
                    { right: t('Right'), center: t('Centre'), left: t('Left') }[config.align ?? 'left'],
                ].filter(Boolean);

                return `${t('Signature and stamp')} — ${parts.join(' · ')}`;
            }

            return t('Block');
        };

        // Shown as one piece that is not typed in, with what it is written on it; stored exactly as
        // the letter's PDF and Word expect it (App\Services\MMS\Letters\SignatureLayouts).
        editor.on('PreInit', () => {
            // A placeholder shows what it is written as: TinyMCE writes it from the page's HTML, so a
            // condition's > showed as &gt;.
            editor.parser.addAttributeFilter('data-mce-content', (nodes) => nodes.forEach((node) => {
                const text = node.attr('data-mce-content') || '';
                if (text.includes('&') && node.firstChild && node.firstChild.type === 3) {
                    node.firstChild.value = decode(text);
                }
            }));

            editor.parser.addAttributeFilter('data-type', (nodes) => nodes.forEach((node) => {
                if (node.attr('data-type') !== 'customBlock') {
                    return;
                }
                node.attr('contenteditable', 'false');
                node.attr('data-label', label(node.attr('data-id'), parseConfig(node.attr('data-config'))));
                node.attr('dir', null);
                while (node.firstChild) {
                    node.firstChild.remove();
                }
            }));

            editor.serializer.addAttributeFilter('data-type', (nodes) => nodes.forEach((node) => {
                if (node.attr('data-type') !== 'customBlock') {
                    return;
                }
                node.attr('contenteditable', null);
                node.attr('data-label', null);
                while (node.firstChild) {
                    node.firstChild.remove();
                }
            }));
        });

        const save = (node, id, config) => {
            const json = JSON.stringify(config);

            if (node) {
                editor.undoManager.transact(() => {
                    editor.dom.setAttrib(node, 'data-config', json);
                    editor.dom.setAttrib(node, 'data-label', label(id, config));
                });
                editor.nodeChanged();
                editor.dispatch('change');

                return;
            }

            editor.insertContent(editor.dom.createHTML('div', { 'data-type': 'customBlock', 'data-id': id, 'data-config': json }));
        };

        const savedDialog = (node, config = {}) => {
            const layouts = blocks().layouts || [];

            if (!layouts.length) {
                editor.notificationManager.open({ text: t('No saved signature blocks yet. They are designed under Settings → Templates → Signature blocks.'), type: 'warning', timeout: 6000 });

                return;
            }

            editor.windowManager.open({
                title: t('Saved signature block'),
                body: {
                    type: 'panel',
                    items: [
                        { type: 'htmlpanel', html: `<p>${escapeHtml(t('Signature blocks are designed under Settings → Templates → Signature blocks.'))}</p>` },
                        { type: 'listbox', name: 'layout_id', label: t('Signature block'), items: layouts.map((layout) => ({ value: String(layout.id), text: layout.name })) },
                    ],
                },
                initialData: { layout_id: String(config.layout_id ?? layouts[0].id) },
                buttons: [
                    { type: 'cancel', text: 'Cancel' },
                    { type: 'submit', text: 'Save', primary: true },
                ],
                onSubmit: (api) => {
                    const id = Number(api.getData().layout_id);
                    // Another block chosen: the copy frozen into an issued letter is the old one's.
                    const next = String(id) === String(config.layout_id) ? { ...config } : { layout_id: id };
                    next.layout_id = id;
                    save(node, blocks().saved, next);
                    api.close();
                },
            });
        };

        const signatureDialog = (node, config = {}) => {
            const current = { title: '', expert: 'matter', name: '', signature: true, stamp: true, align: 'left', ...config };

            editor.windowManager.open({
                title: t('Signature and stamp'),
                body: {
                    type: 'panel',
                    items: [
                        { type: 'htmlpanel', html: `<p>${escapeHtml(t("The expert's name and title, the signature and the stamp, as they sign off the letter."))}</p>` },
                        { type: 'input', name: 'title', label: t('Title above the name'), placeholder: 'الخبير المحاسبي' },
                        { type: 'listbox', name: 'expert', label: t('Name'), items: [
                            { value: 'matter', text: t("The matter's expert") },
                            { value: 'custom', text: t('This name') },
                            { value: 'none', text: t('No name') },
                        ] },
                        { type: 'input', name: 'name', label: t('This name'), enabled: current.expert === 'custom' },
                        { type: 'checkbox', name: 'signature', label: t('Signature') },
                        { type: 'checkbox', name: 'stamp', label: t('Stamp') },
                        { type: 'listbox', name: 'align', label: t('Position'), items: [
                            { value: 'right', text: t('Right') },
                            { value: 'center', text: t('Centre') },
                            { value: 'left', text: t('Left') },
                        ] },
                    ],
                },
                initialData: {
                    title: String(current.title ?? ''),
                    expert: ['matter', 'custom', 'none'].includes(current.expert) ? current.expert : 'matter',
                    name: String(current.name ?? ''),
                    signature: current.signature !== false,
                    stamp: current.stamp !== false,
                    align: ['right', 'center', 'left'].includes(current.align) ? current.align : 'left',
                },
                onChange: (api, details) => {
                    if (details.name === 'expert') {
                        api.setEnabled('name', api.getData().expert === 'custom');
                    }
                },
                buttons: [
                    { type: 'cancel', text: 'Cancel' },
                    { type: 'submit', text: 'Save', primary: true },
                ],
                onSubmit: (api) => {
                    const data = api.getData();

                    if (data.expert === 'custom' && !data.name.trim()) {
                        editor.notificationManager.open({ text: t('Type the name to sign with.'), type: 'error', timeout: 4000 });

                        return;
                    }

                    save(node, blocks().signature, { title: data.title, expert: data.expert, name: data.expert === 'custom' ? data.name : '', signature: data.signature, stamp: data.stamp, align: data.align });
                    api.close();
                },
            });
        };

        const edit = (node) => {
            const id = node.getAttribute('data-id');
            const config = parseConfig(node.getAttribute('data-config'));

            if (id === blocks().saved) {
                savedDialog(node, config);
            } else if (id === blocks().signature) {
                signatureDialog(node, config);
            }
        };

        const selectedBlock = () => editor.dom.getParent(editor.selection.getNode(), BLOCK);

        editor.ui.registry.addMenuButton('wkblocks', {
            icon: 'permanent-pen',
            text: t('Signature blocks'),
            tooltip: t('Signature blocks'),
            fetch: (callback) => callback([
                { type: 'menuitem', text: t('Saved signature block'), onAction: () => savedDialog(null) },
                { type: 'menuitem', text: t('Signature and stamp'), onAction: () => signatureDialog(null) },
            ]),
        });

        editor.ui.registry.addButton('wkblockedit', {
            icon: 'edit-block',
            tooltip: t('Edit'),
            onAction: () => {
                const node = selectedBlock();
                node && edit(node);
            },
        });

        editor.ui.registry.addButton('wkblockremove', {
            icon: 'remove',
            tooltip: t('Remove'),
            onAction: () => {
                const node = selectedBlock();
                if (node) {
                    editor.undoManager.transact(() => editor.dom.remove(node));
                    editor.nodeChanged();
                    editor.dispatch('change');
                }
            },
        });

        editor.ui.registry.addContextToolbar('wkblock', {
            predicate: (node) => node.nodeType === 1 && node.matches(BLOCK),
            items: 'wkblockedit wkblockremove',
            position: 'node',
            scope: 'node',
        });

        editor.on('dblclick', (event) => {
            const node = event.target?.closest?.(BLOCK);
            node && edit(node);

            const tag = event.target?.closest?.('span[data-mce-content]');
            tag && editTag(tag);
        });
    });
}

// A Filament modal keeps the keyboard inside itself, so TinyMCE's dialogs and menus — drawn at the end
// of the page — could not be typed in. While an editor is in a modal, the modal lets go.
function releaseModalFocus(element) {
    const modal = element.closest('.fi-modal');

    if (!modal || !window.Alpine) {
        return;
    }

    try {
        const data = window.Alpine.$data(modal);
        if (data && 'isTrapActive' in data) {
            data.isTrapActive = false;
        }
    } catch {
        // Not a Filament modal after all.
    }
}

export default function richTextField({ state, config, statePath, key, disabled = false }) {
    return {
        state,
        editor: null,
        // The state last sent or received, and the editor's HTML for it (TinyMCE writes text its own
        // way): only a real change is sent — opening and leaving the editor is not one.
        sent: null,
        shown: null,
        timer: null,
        removed: false,

        async init() {
            const tinymce = await loadTinyMce(config.base_url);
            registerPlugin(tinymce);

            if (this.removed || !this.$el.isConnected) {
                return;
            }

            const dark = document.documentElement.classList.contains('dark');
            const field = this.$el.closest('[data-rich-text]');

            const [editor] = await tinymce.init({
                ...config,
                target: this.$refs.textarea,
                skin: dark ? 'oxide-dark' : 'oxide',
                noneditable_regexp: config.noneditable_regexp ? new RegExp(config.noneditable_regexp, 'g') : undefined,
                readonly: disabled,
                wakeel: {
                    ...(config.wakeel || {}),
                    placeholders: () => this.live(field).placeholders || [],
                },
                images_upload_handler: (blobInfo, progress) => this.upload(blobInfo, progress),
                paste_preprocess: (_editor, args) => {
                    args.content = cleanWordHtml(args.content);
                },
                setup: (editor) => {
                    editor.on('init', () => {
                        editor.setContent(this.state ?? '');
                        editor.undoManager.clear();
                        this.sent = this.state;
                        this.shown = editor.getContent();
                    });
                    editor.on('input change undo redo ExecCommand', () => this.queue());
                    editor.on('blur', () => this.push());
                },
            });

            if (this.removed) {
                editor?.remove();

                return;
            }

            this.editor = editor;
            releaseModalFocus(this.$el);

            // Set by the server (another field filled it in): shown — unless it is what was just sent,
            // or the text is being typed in.
            this.$watch('state', (value) => {
                if (!this.editor || value === this.sent || this.editor.hasFocus()) {
                    return;
                }

                this.sent = value;
                this.editor.setContent(value ?? '');
                this.shown = this.editor.getContent();
            });
        },

        live(field) {
            try {
                return JSON.parse(field?.querySelector('[data-rich-text-live]')?.textContent || '{}');
            } catch {
                return {};
            }
        },

        queue() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.push(), 400);
        },

        push() {
            clearTimeout(this.timer);

            if (!this.editor) {
                return;
            }

            const html = this.editor.getContent();

            if (html === this.shown) {
                return;
            }

            this.shown = html;
            this.sent = html;
            this.state = html;
        },

        // A picture pasted or dropped in: uploaded as the field's file attachment, its address put in.
        upload(blobInfo, progress) {
            return new Promise((resolve, reject) => {
                this.$wire.upload(
                    `componentFileAttachments.${statePath}`,
                    blobInfo.blob(),
                    () => this.$wire.callSchemaComponentMethod(key, 'saveUploadedFileAttachmentAndGetUrl').then((url) => (url ? resolve(url) : reject({ message: 'Upload failed', remove: true }))),
                    () => reject({ message: 'Upload failed', remove: true }),
                    (event) => progress(event.detail.progress),
                );
            });
        },

        destroy() {
            this.removed = true;
            clearTimeout(this.timer);
            this.editor?.remove();
            this.editor = null;
        },
    };
}
