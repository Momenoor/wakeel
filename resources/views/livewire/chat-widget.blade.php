@php
    $isPopup = $mode === 'popup';
    $showingThread = (bool) $this->activeConversation;
@endphp

<div
    x-data="{
        fillViewport() {
            const el = this.$refs.pageShell;
            if (! el) { return; }
            el.style.height = Math.max(320, window.innerHeight - el.getBoundingClientRect().top - 24) + 'px';
        },

        {{--
            The popup's bubble can be dragged anywhere, so it never sits on
            top of a page's own buttons. pos is the bubble's top-left in
            viewport pixels (null = the default bottom corner), remembered
            per browser. The panel opens on whichever side has room.
        --}}
        pos: null,
        drag: null,
        moved: false,
        loadPos() {
            try { this.pos = JSON.parse(localStorage.getItem('wakeel.chat.position')); } catch (e) { this.pos = null; }
            this.clamp();
        },
        clamp() {
            if (! this.pos) { return; }
            this.pos = {
                x: Math.min(Math.max(8, this.pos.x), window.innerWidth - 64),
                y: Math.min(Math.max(8, this.pos.y), window.innerHeight - 64),
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
                try { localStorage.setItem('wakeel.chat.position', JSON.stringify(this.pos)); } catch (e) {}
            }
            this.drag = null;
        },
        clicked() {
            if (this.moved) { this.moved = false; return; }
            this.$wire.toggleOpen();
        },
        placement() {
            if (! this.pos) { return ''; }
            const left = this.pos.x < 384;
            const top = this.pos.y < 540;
            return [
                left ? 'left:' + this.pos.x + 'px; right:auto' : 'right:' + (window.innerWidth - this.pos.x - 56) + 'px; left:auto',
                top ? 'top:' + this.pos.y + 'px; bottom:auto' : 'bottom:' + (window.innerHeight - this.pos.y - 56) + 'px; top:auto',
                'flex-direction:' + (top ? 'column-reverse' : 'column'),
                'align-items:' + (left ? 'flex-start' : 'flex-end'),
            ].join('; ');
        },
    }"
    x-init="
        if ($refs.pageShell) {
            fillViewport();
            window.addEventListener('resize', fillViewport);
        }
        if ($refs.bubble) {
            loadPos();
            window.addEventListener('resize', () => clamp());
        }
        if (! Alpine.store('chatOnline')) {
            Alpine.store('chatOnline', { ids: null });
            const join = () => window.Echo.join('online')
                .here((users) => Alpine.store('chatOnline').ids = users.map((u) => u.id))
                .joining((u) => { const s = Alpine.store('chatOnline'); if (s.ids && ! s.ids.includes(u.id)) { s.ids = [...s.ids, u.id]; } })
                .leaving((u) => { const s = Alpine.store('chatOnline'); if (s.ids) { s.ids = s.ids.filter((id) => id !== u.id); } });
            if (window.Echo) { join(); } else { window.addEventListener('EchoLoaded', join, { once: true }); }
        }
    "
    wire:poll.15s.keep-alive="$refresh"
    @if ($isPopup)
        wire:key="chat-widget-popup"
        :style="placement()"
    @endif
    class="fi-chat-widget {{ $isPopup ? 'fixed bottom-6 end-6 z-50 flex flex-col items-end gap-3' : '' }}"
>
    @if ($isPopup)
        {{-- Floating launcher bubble — click to open, drag to move --}}
        <button
            type="button"
            x-ref="bubble"
            x-on:pointerdown="startDrag($event)"
            x-on:pointermove="onDrag($event)"
            x-on:pointerup="endDrag()"
            x-on:pointercancel="endDrag()"
            x-on:click="clicked()"
            style="touch-action: none; user-select: none; cursor: grab;"
            title="{{ __('Drag to move') }}"
            @class([
                'group relative flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-primary-500 to-primary-700 text-white shadow-lg shadow-primary-600/30 ring-1 ring-white/10 transition-transform hover:scale-105 active:scale-95',
            ])
            aria-label="{{ __('Chat') }}"
        >
            <svg wire:loading.remove wire:target="toggleOpen" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-6 w-6 transition-transform duration-200" :class="$wire.isOpen ? 'rotate-90 scale-90 opacity-0 absolute' : ''">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" :class="$wire.isOpen ? '' : 'hidden'">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>

            @if ($this->unreadCount > 0)
                <span
                    x-show="! $wire.isOpen"
                    class="absolute -end-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-danger-500 px-1 text-[11px] font-semibold leading-none text-white ring-2 ring-white dark:ring-gray-950"
                >{{ $this->unreadCount }}</span>
            @endif
        </button>

        <div
            x-show="$wire.isOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 scale-95"
            style="display: none;"
            class="flex h-[32rem] w-[23rem] max-w-[calc(100vw-3rem)] origin-bottom-end flex-col overflow-hidden rounded-3xl border border-gray-950/5 bg-white shadow-2xl shadow-gray-950/20 dark:border-white/10 dark:bg-gray-900"
        >
            @include('livewire.partials.chat-body', ['isPopup' => true, 'showingThread' => $showingThread])
        </div>
    @else
        <div
            x-ref="pageShell"
            class="fi-chat-page grid grid-cols-1 gap-4 overflow-hidden lg:grid-cols-[20rem_1fr]"
        >
            <div class="flex min-h-0 flex-col overflow-hidden rounded-2xl border border-gray-950/5 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                @include('livewire.partials.chat-sidebar')
            </div>

            <div class="flex min-h-0 flex-col overflow-hidden rounded-2xl border border-gray-950/5 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                @include('livewire.partials.chat-thread', ['isPopup' => false])
            </div>
        </div>
    @endif
</div>
