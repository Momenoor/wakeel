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
        const rtl = () => getComputedStyle(html).direction === 'rtl';

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
                    ...this.pos,
                    x: Math.max(8, Math.min(this.pos.x, window.innerWidth - 64)),
                    y: Math.max(8, Math.min(this.pos.y, window.innerHeight - 64)),
                };
            },
            startDrag(e) {
                if (this.drag || (e.button !== undefined && e.button !== 0)) { return; }
                const r = e.currentTarget.getBoundingClientRect();
                this.drag = { dx: e.clientX - r.left, dy: e.clientY - r.top, sx: e.clientX, sy: e.clientY };
                this.moved = false;
                e.currentTarget.setPointerCapture(e.pointerId);
            },
            // The open panel dragged by its header ([data-drag-handle]) — not
            // by a button or a field on it: the bubble goes with it, the panel
            // kept on the side of the bubble it is (pos.left, pos.top — a
            // bubble dragged alone lets its place choose again) and on the
            // screen, top and bottom (fit() keeps it there sideways). Its
            // place is the corner placement() measures from: the bubble's
            // own, or (never dragged) the one the page's CSS puts it in.
            startPanelDrag(e) {
                if (this.drag || this.docked() || (e.button !== undefined && e.button !== 0)) { return; }
                if (! e.target.closest('[data-drag-handle]') || e.target.closest('button, a, input, textarea, select, label')) { return; }
                e.preventDefault();
                const panel = this.$refs.panel;
                const box = panel.parentElement.getBoundingClientRect();
                // The panel and its bubble, both kept in sight — where they are now.
                const r = panel.getBoundingClientRect();
                const b = this.$refs.bubble.getBoundingClientRect();
                const p = { top: Math.min(r.top, b.top), bottom: Math.max(r.bottom, b.bottom) };
                const side = this.pos
                    ? { left: this.pos.left ?? (this.pos.x < 384), top: this.pos.top ?? (this.pos.y < 540) }
                    : { left: rtl(), top: false };
                const x = side.left ? box.left : box.right - 56;
                const y = side.top ? box.top : box.bottom - 56;
                this.drag = {
                    dx: e.clientX - x, dy: e.clientY - y, sx: e.clientX, sy: e.clientY, side,
                    minY: y + 8 - p.top,
                    maxY: y + window.innerHeight - 8 - p.bottom,
                };
                this.moved = false;
                e.currentTarget.setPointerCapture(e.pointerId);
            },
            onDrag(e) {
                if (! this.drag) { return; }
                if (! this.moved && Math.hypot(e.clientX - this.drag.sx, e.clientY - this.drag.sy) < 5) { return; }
                this.moved = true;
                let y = e.clientY - this.drag.dy;
                if (this.drag.side) {
                    // Its top kept in sight first, should the panel be taller than the screen.
                    y = Math.max(this.drag.minY, Math.min(y, this.drag.maxY));
                }
                this.pos = { x: e.clientX - this.drag.dx, y, ...(this.drag.side ?? {}) };
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
            // again on the next page. Pinned, on a wide screen: always open —
            // also once the window is widened; a phone or a narrow window
            // keeps the bubble as it was left there (the server's, by device).
            storeOpen(open) {
                try { sessionStorage.setItem('wakeel.' + name + '.open', open ? '1' : '0'); } catch (e) {}
            },
            restoreOpen() {
                let stored = null;
                try { stored = sessionStorage.getItem('wakeel.' + name + '.open'); } catch (e) {}
                const open = this.docked() || (stored === null ? this.$wire.isOpen : stored === '1');
                if (open !== this.$wire.isOpen) {
                    this.toggle(open);
                }
                this.$watch('docked()', (docked) => { if (docked && ! this.$wire.isOpen) { this.toggle(true); } });
                // Opened by the page itself (a new message): kept too.
                this.$wire.$watch('isOpen', (open) => this.storeOpen(open));
            },
            // Kept on the screen, on any screen: the panel (x-ref="panel") moved
            // sideways (shiftX), and the panel and its bubble together up or
            // down (shiftY, where()), as far as they would run past an edge — a
            // small phone, or the bubble near the side the panel opens toward.
            // Again whenever the bubble moves or the panel is shown, filled or
            // resized, or the window is.
            shiftX: 0,
            shiftY: 0,
            fit() {
                const panel = this.$refs.panel;
                if (! panel || this.docked() || ! panel.offsetWidth || ! panel.offsetParent) { this.shiftX = 0; this.shiftY = 0; return; }
                const box = panel.offsetParent.getBoundingClientRect();
                // Where it is unshifted, and how far it must move to stay 16px from
                // each edge (less, centred, when there is not the room).
                const shift = (start, size, room) => {
                    const edge = Math.min(16, (room - size) / 2);
                    return Math.round(start < edge ? edge - start : Math.min(0, room - edge - (start + size)));
                };
                this.shiftX = shift(box.left + panel.offsetLeft - this.shiftX, panel.offsetWidth, window.innerWidth);
                const top = box.top + panel.offsetTop - this.shiftY;
                const bubble = this.$refs.bubble?.getBoundingClientRect();
                const start = bubble ? Math.min(top, bubble.top - this.shiftY) : top;
                const end = bubble ? Math.max(top + panel.offsetHeight, bubble.bottom - this.shiftY) : top + panel.offsetHeight;
                this.shiftY = shift(start, end - start, window.innerHeight);
            },
            // The bubble and its panel's place (placement()), moved up or down as
            // fit() found they must be — by margins: a transform would hold the
            // chat's own pop-ups (position: fixed) inside it.
            where() {
                if (this.docked()) { return ''; }
                return [this.placement(), 'margin-top:' + this.shiftY + 'px', 'margin-bottom:' + (-this.shiftY) + 'px'].filter(Boolean).join('; ');
            },
            // The bubble at the panel's left or right edge, whichever its place
            // (placement()) is measured from — in Arabic too, where flex-start
            // is the right: the bubble was left off the screen there.
            alignTo(left) {
                return 'align-items:' + (left !== rtl() ? 'flex-start' : 'flex-end');
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
                    const w = this.resizing.w + (rtl() ? dx : -dx);
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
