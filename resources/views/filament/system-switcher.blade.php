@php
    $currentName = $currentSystem === 'pms' ? __('Properties Management System') : __('Matters Management System');
@endphp

{{-- Switching between the systems: at the top of the sidebar (the whole width, the name hidden when the sidebar
     is folded to its icons), or in the top bar when a theme puts the navigation there. --}}
<div @class(['wk-system-switcher-ctn', 'mb-2 px-1' => $inSidebar ?? false])>
    <x-filament::dropdown
        teleport
        placement="bottom-start"
        class="fi-dropdown"
    >
        <x-slot name="trigger">
            <button
                type="button"
                title="{{ $currentName }}"
                @class([
                    'wk-system-switcher flex items-center gap-2 py-1.5 text-sm font-medium rounded-lg text-gray-700 hover:bg-gray-500/5 dark:text-gray-200 dark:hover:bg-white/5',
                    'w-full px-2 ring-1 ring-gray-950/10 dark:ring-white/10' => $inSidebar ?? false,
                    'px-3' => ! ($inSidebar ?? false),
                ])
            >
                <x-filament::icon
                    icon="heroicon-o-squares-2x2"
                    class="w-5 h-5 shrink-0"
                />
                <span
                    class="wk-system-name flex-1 truncate text-start"
                    @if ($inSidebar ?? false) x-show="$store.sidebar.isOpen" @endif
                >{{ $currentName }}</span>
                <x-filament::icon
                    icon="heroicon-m-chevron-down"
                    class="w-4 h-4 shrink-0"
                    @if ($inSidebar ?? false) x-show="$store.sidebar.isOpen" @endif
                />
            </button>
        </x-slot>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                :href="$mmsUrl"
                tag="a"
                :icon="$currentSystem === 'mms' ? 'heroicon-m-check' : null"
            >
                {{ __('Matters Management System') }}
            </x-filament::dropdown.list.item>

            <x-filament::dropdown.list.item
                :href="$pmsUrl"
                tag="a"
                :icon="$currentSystem === 'pms' ? 'heroicon-m-check' : null"
            >
                {{ __('Properties Management System') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
