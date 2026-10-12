{{-- Who can be mentioned: shown while "@name" is being written (todoMentions). --}}
<ul
    x-show="open"
    x-cloak
    class="absolute inset-x-0 top-full z-30 mt-1 max-h-48 overflow-y-auto rounded border border-amber-300 bg-white py-1 text-sm text-gray-900 shadow-lg dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100"
>
    <template x-for="(name, i) in matches" :key="name">
        <li>
            <button
                type="button"
                x-on:mousedown.prevent="pick($refs.field, name)"
                :class="i === idx ? 'bg-amber-100 dark:bg-amber-900/60' : ''"
                class="flex w-full items-center gap-2 px-2 py-1 text-start hover:bg-amber-100 dark:hover:bg-amber-900/60"
            >
                <x-filament::icon icon="heroicon-m-at-symbol" class="h-3.5 w-3.5 text-amber-600" />
                <span x-text="name"></span>
            </button>
        </li>
    </template>
</ul>
