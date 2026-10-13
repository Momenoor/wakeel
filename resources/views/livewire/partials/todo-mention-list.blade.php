{{-- Who can be mentioned: shown while "@name" is being written (todoMentions). --}}
<ul
    x-ref="list"
    x-show="open"
    x-cloak
    class="mt-1 max-h-48 overflow-y-auto rounded border py-1 text-sm shadow-lg border-purple-700 bg-[#0d001f] text-purple-50"
>
    <template x-for="(name, i) in matches" :key="name">
        <li>
            <button
                type="button"
                x-on:mousedown.prevent="pick($refs.field, name)"
                :class="i === idx ? 'bg-[#1a0035]' : ''"
                class="flex w-full items-center gap-2 px-2 py-1 text-start hover:bg-[#1a0035]"
            >
                <x-filament::icon icon="heroicon-m-at-symbol" class="h-3.5 w-3.5 text-purple-600" />
                <span x-text="name"></span>
            </button>
        </li>
    </template>
</ul>
