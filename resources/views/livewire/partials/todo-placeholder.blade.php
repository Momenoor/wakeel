{{--
    The to-do bubble until the note has loaded, in the background, after the
    page: the same bubble where it was dragged to, so nothing moves when the
    note arrives.
--}}
<div>
<div
    wire:key="todo-note-placeholder"
    x-data="{
        style: '',
        init() {
            try { const p = JSON.parse(localStorage.getItem('wakeel.todo.position')); if (p) { this.style = 'left:' + p.x + 'px; top:' + p.y + 'px; right:auto; bottom:auto'; } } catch (e) {}
        },
    }"
    x-bind:style="style"
    class="fi-todo-note fixed bottom-6 end-24 z-40"
>
    <span
        class="flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-amber-300 to-amber-500 text-amber-950 shadow-lg shadow-amber-600/30 ring-1 ring-black/5"
        aria-label="{{ __('To-do list') }}"
    >
        <x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-6 w-6 opacity-80" />
    </span>
</div>
</div>
