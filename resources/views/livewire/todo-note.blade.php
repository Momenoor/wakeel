{{--
    An outer element of its own: Livewire swaps the placeholder for this, and
    the two inner elements carry different keys, so the placeholder's Alpine
    state is replaced, not kept — kept, none of the functions below existed
    ("placement is not defined", the pin and drag dead).
--}}
<div>
<div
    x-data="{
        {{-- The bubble can be dragged anywhere (its own place, not the chat's), remembered per browser. --}}
        pos: null,
        drag: null,
        moved: false,
        loadPos() {
            try { this.pos = JSON.parse(localStorage.getItem('wakeel.todo.position')); } catch (e) { this.pos = null; }
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
                try { localStorage.setItem('wakeel.todo.position', JSON.stringify(this.pos)); } catch (e) {}
            }
            this.drag = null;
        },
        clicked() {
            if (this.moved) { this.moved = false; return; }
            this.toggle(! this.$wire.isOpen);
        },
        {{-- Shown or hidden at once; the server keeps it for the next page. --}}
        toggle(open) {
            this.$wire.isOpen = open;
            this.$wire.setOpen(open);
            this.storeOpen(open);
        },
        {{-- Open or minimised, kept for this tab's next pages: the browser's
             own word wins — the server's session could be written back by
             another request running at the same time, and the bubble opened
             again on the next page. Pinned: always open. --}}
        storeOpen(open) {
            try { sessionStorage.setItem('wakeel.todo.open', open ? '1' : '0'); } catch (e) {}
        },
        restoreOpen() {
            let stored = null;
            try { stored = sessionStorage.getItem('wakeel.todo.open'); } catch (e) {}
            if (stored !== null && ! this.docked() && (stored === '1') !== this.$wire.isOpen) {
                this.$wire.isOpen = stored === '1';
                this.$wire.setOpen(stored === '1');
            }
            // Opened by the page itself (a new message): kept too.
            this.$wire.$watch('isOpen', (open) => this.storeOpen(open));
        },
        {{-- The note's width: dragged by either side border, saved for the user when let go. --}}
        width: @js($width),
        resizing: null,
        startResize(e, side) {
            this.resizing = { side, x: e.clientX, w: this.width };
            e.currentTarget.setPointerCapture(e.pointerId);
        },
        onResize(e) {
            if (! this.resizing) { return; }
            const dx = e.clientX - this.resizing.x;
            const w = this.resizing.w + (this.resizing.side === 'left' ? -dx : dx);
            this.width = Math.round(Math.min(Math.max({{ \App\Livewire\TodoNote::MIN_WIDTH }}, w), Math.min({{ \App\Livewire\TodoNote::MAX_WIDTH }}, window.innerWidth - 32)));
        },
        endResize() {
            if (! this.resizing) { return; }
            this.resizing = null;
            this.$wire.saveWidth(this.width);
        },
        {{-- Pinned to the page (the dock store): a column, not a bubble. --}}
        docked() {
            return this.$store.dock?.on('todo') ?? false;
        },
        pin(pinned) {
            this.$store.dock.set('todo', pinned);
            this.$wire.isOpen = true;
            this.$wire.pin(pinned);
        },
        placement() {
            if (! this.pos || this.docked()) { return ''; }
            const left = this.pos.x < 384;
            const top = this.pos.y < 540;
            return [
                left ? 'left:' + this.pos.x + 'px; right:auto' : 'right:' + (window.innerWidth - this.pos.x - 56) + 'px; left:auto',
                top ? 'top:' + this.pos.y + 'px; bottom:auto' : 'bottom:' + (window.innerHeight - this.pos.y - 56) + 'px; top:auto',
                {{-- The note opens away from the edge it is near. --}}
                'flex-direction:' + (top ? 'column' : 'column-reverse'),
                'align-items:' + (left ? 'flex-start' : 'flex-end'),
            ].join('; ');
        },
    }"
    x-init="loadPos(); restoreOpen(); window.addEventListener('resize', () => clamp())"
    x-on:keydown.escape.window="if ($wire.isOpen && ! docked()) { toggle(false) }"
    :style="placement()"
    :class="docked() ? 'wakeel-docked wakeel-docked-todo' : ''"
    wire:key="todo-note-body"
    class="fi-todo-note fixed bottom-6 end-24 z-40 flex flex-col-reverse items-end gap-3"
>
    {{-- The bubble: click to show or hide, drag to move. --}}
    <button
        type="button"
        x-show="! docked()"
        x-on:pointerdown="startDrag($event)"
        x-on:pointermove="onDrag($event)"
        x-on:pointerup="endDrag()"
        x-on:pointercancel="endDrag()"
        x-on:click="clicked()"
        style="touch-action: none; user-select: none; cursor: grab;"
        title="{{ __('To-do list') }} — {{ __('Drag to move') }}"
        aria-label="{{ __('To-do list') }}"
        class="relative flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-amber-300 to-amber-500 text-amber-950 shadow-lg shadow-amber-600/30 ring-1 ring-black/5 transition-transform hover:scale-105 active:scale-95"
    >
        <x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-6 w-6" x-show="! $wire.isOpen" />
        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" x-show="$wire.isOpen" x-cloak />

        @if ($this->dueCount > 0)
            <span
                x-show="! $wire.isOpen"
                class="absolute -end-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-danger-500 px-1 text-[11px] font-semibold leading-none text-white ring-2 ring-white dark:ring-gray-950"
            >{{ $this->dueCount }}</span>
        @endif
    </button>

    {{-- The note. --}}
    <div
        x-show="$wire.isOpen || docked()"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 scale-95"
        style="display: none;"
        :style="docked() ? {} : { width: width + 'px' }"
        :class="resizing ? 'select-none' : ''"
        class="wakeel-dock-panel relative flex max-h-[min(32rem,calc(100vh-7rem))] max-w-[calc(100vw-2rem)] -rotate-[0.4deg] flex-col overflow-hidden rounded-md border border-amber-300/70 bg-amber-100 text-amber-950 shadow-2xl shadow-amber-900/20 dark:border-amber-500/30 dark:bg-amber-950 dark:text-amber-50"
    >
{{-- Pinned: its edge toward the page drags the column wider or narrower. --}}
        <div
            x-show="docked()"
            x-on:pointerdown.prevent="$store.dock.startResize($event)"
            x-on:pointermove="$store.dock.onResize($event)"
            x-on:pointerup="$store.dock.endResize($wire)"
            x-on:pointercancel="$store.dock.endResize($wire)"
            style="touch-action: none;"
            class="absolute inset-y-0 start-0 z-20 w-2 cursor-ew-resize transition-colors hover:bg-primary-400/50"
            title="{{ __('Drag to resize') }}"
        ></div>

        {{-- Either side border: drag to make the note wider or narrower. --}}
        @foreach (['left', 'right'] as $side)
            <div
                x-show="! docked()"
                x-on:pointerdown.prevent="startResize($event, '{{ $side }}')"
                x-on:pointermove="onResize($event)"
                x-on:pointerup="endResize()"
                x-on:pointercancel="endResize()"
                style="touch-action: none;"
                class="absolute inset-y-0 {{ $side === 'left' ? 'left-0' : 'right-0' }} z-10 w-1.5 cursor-ew-resize transition-colors hover:bg-amber-400/60"
                title="{{ __('Drag to resize') }}"
            ></div>
        @endforeach

        <div class="flex items-center gap-2 border-b border-amber-300/70 bg-amber-200/70 px-4 py-2.5 dark:border-amber-500/30 dark:bg-amber-900/60">
            <x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-5 w-5" />
            <h2 class="flex-1 text-sm font-semibold">{{ __('To-do list') }}</h2>
            <button type="button" wire:click="$toggle('allDone')" class="text-xs underline-offset-2 hover:underline">
                {{ $allDone ? __('Hide done') : __('Show done') }}
            </button>
            {{-- Pinned to the page as a column, or a bubble again. Wide screens only:
         max-lg:hidden, not "hidden lg:…" — the tour plugin's .hidden is !important. --}}
            <button
                type="button"
                x-on:click="pin(! docked())"
                class="block rounded p-1 hover:bg-amber-300/60 max-lg:hidden dark:hover:bg-amber-800"
                :class="docked() ? 'bg-amber-300/70 dark:bg-amber-800' : ''"
                :title="docked() ? @js(__('Unpin from the page')) : @js(__('Pin to the page'))"
            >
                <x-filament::icon icon="heroicon-o-view-columns" class="h-4 w-4" />
            </button>
            <button type="button" x-show="! docked()" x-on:click="toggle(false)" class="rounded p-1 hover:bg-amber-300/60 dark:hover:bg-amber-800" aria-label="{{ __('Hide') }}">
                <x-filament::icon icon="heroicon-o-minus" class="h-4 w-4" />
            </button>
        </div>

        @if ($isOpen)
            {{-- A new line: what, and for which day. --}}
            <form wire:submit="add" class="flex flex-col gap-2 border-b border-amber-300/70 px-4 py-3 dark:border-amber-500/30">
                {{-- items-start: the suggestions open under the field, the button keeps its size. --}}
                <div class="flex items-start gap-2">
                    <div class="relative min-w-0 flex-1" x-data="todoMentions(@js($this->people))">
                    <input
                        type="text"
                        x-ref="field"
                        wire:model="title"
                        x-on:input="scan($el)"
                        x-on:keydown="key($event, $el)"
                        x-on:blur="open = false"
                        maxlength="500"
                        placeholder="{{ __('Add a to-do… (@ to mention someone)') }}"
                        class="w-full rounded border-amber-300 bg-white/70 px-2 py-1.5 text-sm text-gray-900 placeholder-amber-700/60 focus:border-amber-500 focus:ring-amber-500 dark:border-amber-700 dark:bg-amber-900/40 dark:text-amber-50"
                    >
                    @include('livewire.partials.todo-mention-list')
                    </div>
                    <button type="submit" class="h-[34px] rounded bg-amber-500 px-3 text-sm font-semibold text-amber-950 hover:bg-amber-400" aria-label="{{ __('Add') }}">
                        <x-filament::icon icon="heroicon-m-plus" class="h-4 w-4" />
                    </button>
                </div>
                <input
                    type="date"
                    wire:model="dueOn"
                    class="w-40 rounded border-amber-300 bg-white/70 px-2 py-1 text-xs text-gray-900 focus:border-amber-500 focus:ring-amber-500 dark:border-amber-700 dark:bg-amber-900/40 dark:text-amber-50"
                    aria-label="{{ __('Date') }}"
                >
                @error('title') <p class="text-xs text-danger-600">{{ $message }}</p> @enderror
            </form>

            <div class="flex-1 overflow-y-auto px-4 py-2">
                @forelse ($this->groups as $group)
                    <section wire:key="day-{{ $group['date'] }}" class="py-2">
                        <h3 @class(['mb-1 text-xs font-bold uppercase tracking-wide', 'text-danger-600 dark:text-danger-400' => $group['overdue'], 'text-amber-800 dark:text-amber-300' => ! $group['overdue']])>
                            {{ $group['label'] }}
                            @if ($group['overdue']) · {{ __('Late (to-do)') }} @endif
                        </h3>

                        <ul class="flex flex-col">
                            @foreach ($group['todos'] as $todo)
                                <li
                                    wire:key="todo-{{ $todo->id }}"
                                    x-data="{ editing: false, title: @js($todo->title), date: @js($todo->due_on->toDateString()) }"
                                    class="group flex items-start gap-2 rounded px-1 py-1 hover:bg-amber-200/50 dark:hover:bg-amber-900/50"
                                >
                                    <input
                                        type="checkbox"
                                        @checked($todo->done_at)
                                        wire:click="toggle({{ $todo->id }})"
                                        class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-amber-500 text-amber-600 focus:ring-amber-500"
                                        aria-label="{{ __('Done') }}"
                                    >

                                    <div class="min-w-0 flex-1" x-show="! editing">
                                        <p
                                            @if ($todo->user_id === auth()->id()) x-on:dblclick="editing = true; $nextTick(() => $refs.edit.focus())" @endif
                                            @class(['break-words text-sm', 'text-amber-800/60 line-through dark:text-amber-200/50' => $todo->done_at])
                                        >{!! $todo->titleHtml() !!}</p>
                                        @if ($todo->user_id !== auth()->id())
                                            <p class="text-[11px] text-amber-700 dark:text-amber-300">{{ __('From :name', ['name' => $todo->user?->name]) }}</p>
                                        @elseif ($todo->sharedWith->isNotEmpty())
                                            <p class="text-[11px] text-amber-700 dark:text-amber-300">{{ __('Shared with :names', ['names' => $todo->sharedWith->pluck('name')->implode('، ')]) }}</p>
                                        @endif
                                        @if ($todo->done_at && $todo->doneBy && $todo->participants()->count() > 1)
                                            <p class="text-[11px] text-success-700 dark:text-success-400">{{ __('Done by :name', ['name' => $todo->doneBy->name]) }}</p>
                                        @endif
                                    </div>

                                    {{-- Changed in place: Enter keeps it, Escape leaves it. --}}
                                    <form
                                        x-show="editing"
                                        x-cloak
                                        x-on:submit.prevent="editing = false; $wire.change({{ $todo->id }}, title, date)"
                                        x-on:keydown.escape.stop="editing = false"
                                        class="flex min-w-0 flex-1 flex-col gap-1"
                                    >
                                        <div class="relative" x-data="todoMentions(@js($this->people))">
                                            <input x-ref="edit" x-init="$refs.field = $el" type="text" x-model="title" x-on:input="scan($el)" x-on:keydown="key($event, $el)" x-on:blur="open = false" maxlength="500" class="w-full rounded border-amber-300 bg-white/80 px-2 py-1 text-sm text-gray-900 dark:bg-amber-900/40 dark:text-amber-50">
                                            @include('livewire.partials.todo-mention-list')
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <input type="date" x-model="date" class="rounded border-amber-300 bg-white/80 px-2 py-0.5 text-xs text-gray-900 dark:bg-amber-900/40 dark:text-amber-50">
                                            <button type="submit" class="text-xs font-semibold underline">{{ __('Save') }}</button>
                                        </div>
                                    </form>

                                    <div class="flex shrink-0 items-center gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100" x-show="! editing">
                                        @if ($todo->user_id === auth()->id())
                                        <button type="button" x-on:click="editing = true; $nextTick(() => $refs.edit.focus())" class="rounded p-0.5 hover:bg-amber-300/70 dark:hover:bg-amber-800" title="{{ __('Edit') }}">
                                            <x-filament::icon icon="heroicon-m-pencil" class="h-3.5 w-3.5" />
                                        </button>
                                        @unless ($todo->done_at)
                                            <button type="button" wire:click="postpone({{ $todo->id }})" class="rounded p-0.5 hover:bg-amber-300/70 dark:hover:bg-amber-800" title="{{ __('To the next day') }}">
                                                <x-filament::icon icon="heroicon-m-arrow-right-circle" class="h-3.5 w-3.5 rtl:rotate-180" />
                                            </button>
                                        @endunless
                                        <button type="button" wire:click="remove({{ $todo->id }})" wire:confirm="{{ __('Delete this to-do?') }}" class="rounded p-0.5 text-danger-700 hover:bg-amber-300/70 dark:text-danger-400 dark:hover:bg-amber-800" title="{{ __('Delete') }}">
                                            <x-filament::icon icon="heroicon-m-trash" class="h-3.5 w-3.5" />
                                        </button>
                                        @else
                                        <button type="button" wire:click="remove({{ $todo->id }})" wire:confirm="{{ __('Take this to-do off your list?') }}" class="rounded p-0.5 hover:bg-amber-300/70 dark:hover:bg-amber-800" title="{{ __('Leave') }}">
                                            <x-filament::icon icon="heroicon-m-arrow-right-start-on-rectangle" class="h-3.5 w-3.5" />
                                        </button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @empty
                    <p class="py-8 text-center text-sm text-amber-800/70 dark:text-amber-200/60">{{ __('Nothing to do. Add the first one above.') }}</p>
                @endforelse
            </div>

            @if ($allDone)
                <div class="border-t border-amber-300/70 px-4 py-2 text-end dark:border-amber-500/30">
                    <button type="button" wire:click="clearDone" wire:confirm="{{ __('Delete all the done to-dos?') }}" class="text-xs text-danger-700 underline-offset-2 hover:underline dark:text-danger-400">
                        {{ __('Clear done') }}
                    </button>
                </div>
            @endif
        @else
            <div class="flex h-40 items-center justify-center">
                <x-filament::loading-indicator class="h-6 w-6 text-amber-600" />
            </div>
        @endif
    </div>
</div>
</div>
