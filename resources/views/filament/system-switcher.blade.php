@php
    $currentName = $currentSystem === 'pms' ? __('Properties Management System') : __('Matters Management System');
    $systems = [
        'mms' => ['label' => __('Matters Management System'), 'url' => $mmsUrl],
        'pms' => ['label' => __('Properties Management System'), 'url' => $pmsUrl],
    ];
@endphp

@if ($inSidebar ?? false)
    {{-- At the top of the sidebar: the systems open as a list right under the button, inside the sidebar — a
         floating menu opened from the sidebar's edge ran off the screen (right to left, on a phone). Folded to
         its icons, the sidebar opens first. --}}
    <div x-data="{ open: false }" class="wk-system-switcher-ctn mb-2 px-1">
        <button
            type="button"
            title="{{ $currentName }}"
            x-on:click="if (! $store.sidebar.isOpen) { $store.sidebar.open(); open = true } else { open = ! open }"
            x-bind:aria-expanded="open"
            class="wk-system-switcher flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 hover:bg-gray-500/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5"
        >
            <x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5 shrink-0" />
            <span class="wk-system-name flex-1 truncate text-start" x-show="$store.sidebar.isOpen">{{ $currentName }}</span>
            <x-filament::icon
                icon="heroicon-m-chevron-down"
                class="h-4 w-4 shrink-0 transition-transform"
                x-show="$store.sidebar.isOpen"
                x-bind:class="open && 'rotate-180'"
            />
        </button>

        <div x-show="open && $store.sidebar.isOpen" x-transition.opacity x-cloak class="mt-1 flex flex-col gap-0.5">
            @foreach ($systems as $key => $system)
                <a
                    href="{{ $system['url'] }}"
                    @class([
                        'flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-500/5 dark:hover:bg-white/5',
                        'font-semibold text-primary-600 dark:text-primary-400' => $currentSystem === $key,
                        'text-gray-700 dark:text-gray-200' => $currentSystem !== $key,
                    ])
                >
                    <x-filament::icon
                        :icon="$currentSystem === $key ? 'heroicon-m-check' : 'heroicon-m-arrow-right-circle'"
                        class="h-4 w-4 shrink-0"
                    />
                    <span class="truncate">{{ $system['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@else
    {{-- In the top bar (a theme with the navigation on top): a menu under the button. --}}
    <x-filament::dropdown teleport placement="bottom-end" class="fi-dropdown">
        <x-slot name="trigger">
            <button
                type="button"
                title="{{ $currentName }}"
                class="wk-system-switcher flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-500/5 dark:text-gray-200 dark:hover:bg-white/5"
            >
                <x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5 shrink-0" />
                <span class="wk-system-name truncate">{{ $currentName }}</span>
                <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 shrink-0" />
            </button>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($systems as $key => $system)
                <x-filament::dropdown.list.item
                    :href="$system['url']"
                    tag="a"
                    :icon="$currentSystem === $key ? 'heroicon-m-check' : null"
                >
                    {{ $system['label'] }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
