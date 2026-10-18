{{--
    The to-do note and the chat pinned to the page (App\Support\Dock): the
    `dock` store each of them follows, and the page's own part — room made
    beside the content (html[data-dock*], theme.css) and the navigation menu
    collapsed while anything is pinned, opened again once nothing is.
    Wide screens only; on a narrow one both stay bubbles.
--}}
@php
    $dock = \App\Support\Dock::state(auth()->user());
    // No chat bubble on the Chat page itself (nor for whoever has no chat).
    $chatHere = \App\Filament\Mms\Pages\Chat::canAccess() && ! request()->routeIs('filament.mms.pages.chat');
@endphp
<script>
    (() => {
        const pinned = { todo: @js($dock['todo']), chat: @js($dock['chat'] && $chatHere) };
        // The same width the pin buttons appear from (Tailwind's lg: 64rem).
        const wide = () => window.matchMedia('(min-width: 64rem)').matches;
        const html = document.documentElement;

        // At once, before the page is drawn: no jump when Alpine starts.
        html.style.setProperty('--wakeel-dock-w', '{{ \App\Support\Dock::width(auth()->user()) }}px');
        if (wide()) {
            html.toggleAttribute('data-dock', pinned.todo || pinned.chat);
            html.toggleAttribute('data-dock-todo', pinned.todo);
            html.toggleAttribute('data-dock-chat', pinned.chat);
        }

        document.addEventListener('alpine:init', () => {
        // The to-do and chat bubbles: dragged anywhere on screen (the place is
        // kept in this browser), clicked to open or shut (the answer is kept
        // for this tab's next pages), and — on touch screens — pinched with two
        // fingers. Each widget passes its own name ('todo', 'chat') for the
        // keys it keeps, and its own parts as $extra (docked(), pin(), …).
        Alpine.data('wkBubble', (name, extra = {}) => ({
            pos: null,
            drag: null,
            moved: false,
            loadPos() {
                try { this.pos = JSON.parse(localStorage.getItem('wakeel.' + name + '.position')); } catch (e) { this.pos = null; }
                this.clamp();
            },
            clamp() {
                if (! this.pos) { return; }
                this.pos = {
                    x: Math.max(8, Math.min(this.pos.x, window.innerWidth - 64)),
                    y: Math.max(8, Math.min(this.pos.y, window.innerHeight - 64)),
                };
            },
            startDrag(e) {
                if (e.button !== undefined && e.button !== 0) { return; }
                const r = e.currentTarget.getBoundingClientRect();
                this.drag = { dx: e.clientX - r.left, dy: e.clientY - r.top, sx: e.clientX, sy: e.clientY };
                this.moved = false;
                e.currentTarget.setPointerCapture(e.pointerId);
            },
            onDrag(e) {
                if (! this.drag) { return; }
                if (! this.moved && Math.hypot(e.clientX - this.drag.sx, e.clientY - this.drag.sy) < 5) { return; }
                this.moved = true;
                this.pos = { x: e.clientX - this.drag.dx, y: e.clientY - this.drag.dy };
                this.clamp();
            },
            endDrag() {
                if (this.drag && this.moved) {
                    try { localStorage.setItem('wakeel.' + name + '.position', JSON.stringify(this.pos)); } catch (e) {}
                }
                this.drag = null;
            },
            clicked() {
                if (this.moved) { this.moved = false; return; }
                this.toggle(! this.$wire.isOpen);
            },
            // Shown or hidden at once, here; the server keeps it (and fills the
            // panel when opened) meanwhile. Waiting for it first made every
            // open and close take a round trip. Open or shut only: a voice
            // note's play button (its own toggle()) falls through to this one
            // when its player didn't start, with no answer.
            toggle(open) {
                if (typeof open !== 'boolean') { return; }
                this.$wire.isOpen = open;
                this.$wire.setOpen(open);
                this.storeOpen(open);
            },
            // Open or minimised, kept for this tab's next pages: the browser's
            // own word wins — the server's session could be written back by
            // another request running at the same time, and the bubble opened
            // again on the next page. Pinned: always open.
            storeOpen(open) {
                try { sessionStorage.setItem('wakeel.' + name + '.open', open ? '1' : '0'); } catch (e) {}
            },
            restoreOpen() {
                let stored = null;
                try { stored = sessionStorage.getItem('wakeel.' + name + '.open'); } catch (e) {}
                if (stored !== null && ! this.docked() && (stored === '1') !== this.$wire.isOpen) {
                    this.$wire.isOpen = stored === '1';
                    this.$wire.setOpen(stored === '1');
                }
                // Opened by the page itself (a new message): kept too.
                this.$wire.$watch('isOpen', (open) => this.storeOpen(open));
            },
            // The distance between two fingers, for a pinch.
            gap(e) {
                return Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
            },
            ...extra,
        }));
            Alpine.store('dock', {
                todo: pinned.todo,
                chat: pinned.chat,
                wide: wide(),
                width: {{ \App\Support\Dock::width(auth()->user()) }},
                resizing: null,
                {{-- Dragged by its edge toward the page; kept for the user when let go ($wire: the panel's). --}}
                startResize(e) {
                    this.resizing = { x: e.clientX, w: this.width };
                    e.currentTarget.setPointerCapture(e.pointerId);
                },
                onResize(e) {
                    if (! this.resizing) {
                        return;
                    }
                    // The column is at the page's end: dragging toward the start widens it.
                    const dx = e.clientX - this.resizing.x;
                    const rtl = getComputedStyle(html).direction === 'rtl';
                    const w = this.resizing.w + (rtl ? dx : -dx);
                    this.width = Math.round(Math.min(Math.max({{ \App\Support\Dock::MIN_WIDTH }}, w), Math.min({{ \App\Support\Dock::MAX_WIDTH }}, window.innerWidth / 2)));
                    html.style.setProperty('--wakeel-dock-w', this.width + 'px');
                },
                endResize($wire) {
                    if (! this.resizing) {
                        return;
                    }
                    this.resizing = null;
                    $wire.saveDockWidth(this.width);
                },
                on(which) {
                    return this.wide && this[which];
                },
                get any() {
                    return this.on('todo') || this.on('chat');
                },
                init() {
                    window.addEventListener('resize', () => {
                        this.wide = wide();
                        this.apply();
                    });
                },
                set(which, value) {
                    this[which] = value;
                    this.apply(true);
                },
                apply(changed = false) {
                    // Below the top bar, beside the page's content only.
                    const topbar = document.querySelector('.fi-topbar');
                    if (topbar) {
                        html.style.setProperty('--wakeel-dock-top', topbar.getBoundingClientRect().height + 'px');
                    }
                    html.toggleAttribute('data-dock', this.any);
                    html.toggleAttribute('data-dock-todo', this.on('todo'));
                    html.toggleAttribute('data-dock-chat', this.on('chat'));

                    const sidebar = Alpine.store('sidebar');
                    if (! sidebar || ! this.wide) {
                        return;
                    }
                    // The menu is out of the way while anything is pinned; otherwise it is as the
                    // user left it (isOpenDesktop, which Filament keeps in this browser). Closed here
                    // by isOpen alone — close() would write the user's choice too, and the menu
                    // came back open after a pin the user had collapsed it before.
                    const wanted = this.any ? false : sidebar.isOpenDesktop !== false;
                    if (sidebar.isOpen !== wanted) {
                        sidebar.isOpen = wanted;
                    }
                },
            });
        });

        document.addEventListener('alpine:initialized', () => Alpine.store('dock').apply());
    })();
</script>
