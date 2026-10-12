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
        html.style.setProperty('--wakeel-dock-w', '{{ \App\Support\Dock::WIDTH }}px');
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
