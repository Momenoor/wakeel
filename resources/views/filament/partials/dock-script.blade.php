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
                    if (this.any && sidebar.isOpen) {
                        sidebar.close();
                    } else if (changed && ! this.any && ! sidebar.isOpen) {
                        sidebar.open();
                    }
                },
            });
        });

        document.addEventListener('alpine:initialized', () => Alpine.store('dock').apply());
    })();
</script>
