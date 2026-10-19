{{--
    The PDF splitter (resources/js/pdf-splitter.js). Everything inside is drawn by Alpine, so Livewire leaves it
    alone (wire:ignore); Livewire is only asked for the user's OneDrive folders and to note the split in the log.
--}}
@php
    $config = $this->splitterConfig();
    $card = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $field = 'block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white';
@endphp

<x-filament-panels::page>
    <div
        wire:ignore
        x-load
        x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/pdf-splitter.js') }}"
        x-data="pdfSplitter(@js($config))"
        class="flex flex-col gap-4"
    >
        {{-- The PDF --}}
        <section class="{{ $card }}" x-on:dragover.prevent x-on:drop.prevent="pick($event)">
            <div class="flex flex-wrap items-center gap-2">
                <label class="fi-btn relative inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                    x-bind:class="busy && 'pointer-events-none opacity-50'">
                    <x-filament::icon icon="heroicon-o-folder-open" class="size-5" />
                    <span>{{ __('Choose a PDF') }}</span>
                    <input type="file" accept="application/pdf,.pdf" class="sr-only" x-on:change="pick($event)" x-bind:disabled="busy">
                </label>

                <x-filament::button color="gray" icon="heroicon-o-cloud" :loading-indicator="false" x-on:click="openPicker()" x-bind:disabled="busy">
                    {{ __('From OneDrive') }}
                </x-filament::button>
            </div>

            <div x-show="file" x-cloak class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                <span class="flex min-w-0 items-center gap-2 font-medium text-gray-950 dark:text-white">
                    <x-filament::icon icon="heroicon-o-document" class="size-5 shrink-0 text-gray-400" />
                    <span class="truncate" dir="auto" x-text="file?.name"></span>
                </span>
                <span class="text-gray-600 dark:text-gray-400"><span x-text="file && sizeOf(file.size)"></span></span>
                <span class="text-gray-600 dark:text-gray-400">{{ __('Pages') }}: <span class="tabular-nums" x-text="pageCount"></span></span>
                <span class="text-gray-600 dark:text-gray-400" x-show="file?.source === 'onedrive'">{{ __('From OneDrive') }}</span>
            </div>

            <div x-show="! file" class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                <p>{{ __('Choose a PDF from your computer or your OneDrive, or drop it here.') }}</p>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ __('The PDF is split in your browser and is not uploaded: only a note that you split it is kept.') }}
                {{ __('Very large files are best split on a computer: a phone may run out of memory.') }}
            </p>
        </section>

        <div x-show="error" x-cloak class="flex items-start justify-between gap-2 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400">
            <span x-text="error"></span>
            <button type="button" class="font-semibold" x-on:click="error = null" aria-label="{{ __('Close') }}">&times;</button>
        </div>

        {{-- How to split --}}
        <section x-show="file" x-cloak class="{{ $card }}">
            <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ __('How to split') }}</h3>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex cursor-pointer flex-col gap-2 rounded-lg p-3 ring-1" x-bind:class="mode === 'size' ? 'ring-2 ring-primary-500' : 'ring-gray-200 dark:ring-white/10'">
                    <span class="flex items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
                        <input type="radio" value="size" class="text-primary-600" x-model="mode" x-bind:disabled="busy">
                        {{ __('By size') }}
                    </span>
                    <span class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <span>{{ __('At most') }}</span>
                        <input type="number" min="0.5" step="0.5" inputmode="decimal" class="{{ $field }} w-24" dir="ltr" x-model="maxMb" x-on:focus="mode = 'size'" x-bind:disabled="busy">
                        <span>{{ __('MB per part') }}</span>
                    </span>
                </label>

                <label class="flex cursor-pointer flex-col gap-2 rounded-lg p-3 ring-1" x-bind:class="mode === 'pages' ? 'ring-2 ring-primary-500' : 'ring-gray-200 dark:ring-white/10'">
                    <span class="flex items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
                        <input type="radio" value="pages" class="text-primary-600" x-model="mode" x-bind:disabled="busy">
                        {{ __('By pages') }}
                    </span>
                    <span class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <input type="number" min="1" step="1" inputmode="numeric" class="{{ $field }} w-24" dir="ltr" x-model="perPart" x-on:focus="mode = 'pages'" x-bind:disabled="busy">
                        <span>{{ __('pages per part') }}</span>
                    </span>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <x-filament::button icon="heroicon-o-scissors" :loading-indicator="false" x-on:click="split()" x-bind:disabled="busy || ! file">
                    {{ __('Split') }}
                </x-filament::button>
                <x-filament::button color="gray" :loading-indicator="false" x-show="busy && file" x-on:click="cancel()">
                    {{ __('Cancel') }}
                </x-filament::button>
            </div>
        </section>

        {{-- What it is doing --}}
        <section x-show="busy" x-cloak class="{{ $card }}">
            <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                <x-filament::loading-indicator class="size-5 text-primary-600" />
                <span x-text="status"></span>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" x-show="percent !== null">
                <div class="h-full rounded-full bg-primary-600 transition-[width]" x-bind:style="{ width: (percent ?? 0) + '%' }"></div>
            </div>
        </section>

        {{-- The parts --}}
        <section x-show="parts.length && ! busy" x-cloak class="{{ $card }}">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ __('Parts') }}: <span class="tabular-nums" x-text="parts.length"></span>
                </h3>
                <x-filament::button icon="heroicon-o-arrow-down-tray" :loading-indicator="false" x-on:click="downloadZip()">
                    {{ __('Download all (zip)') }}
                </x-filament::button>
            </div>

            <template x-for="warning in warnings" :key="warning">
                <p class="mb-2 rounded-lg bg-warning-50 p-2 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400" x-text="warning"></p>
            </template>

            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                <template x-for="(piece, index) in parts" :key="piece.name">
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-sm">
                        <span class="min-w-0 flex-1 truncate font-medium text-gray-950 dark:text-white" dir="auto" x-text="piece.name"></span>
                        <span class="text-gray-600 dark:text-gray-400" x-text="pagesOf(piece)"></span>
                        <span class="tabular-nums" x-bind:class="piece.over ? 'font-semibold text-warning-600 dark:text-warning-400' : 'text-gray-600 dark:text-gray-400'" x-text="sizeOf(piece.size)"></span>
                        <x-filament::icon-button icon="heroicon-o-arrow-down-tray" color="gray" :label="__('Download')" x-on:click="downloadPart(index)" />
                    </li>
                </template>
            </ul>
        </section>

        {{-- From OneDrive --}}
        <div x-show="pickerOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" x-on:click.self="pickerOpen = false" x-on:keydown.escape.window="pickerOpen = false">
            <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl dark:bg-gray-900" x-trap.noscroll="pickerOpen">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 p-4 dark:border-white/10">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('From OneDrive') }}</h3>
                    <x-filament::icon-button icon="heroicon-o-x-mark" color="gray" :label="__('Close')" x-on:click="pickerOpen = false" />
                </div>

                <div class="flex flex-col gap-3 p-4">
                    <input type="search" class="{{ $field }}" placeholder="{{ __('Search your OneDrive') }}" x-model="search" x-on:input.debounce.500ms="find()">

                    <nav class="flex flex-wrap items-center gap-1 text-sm" x-show="search.trim().length < 2">
                        <button type="button" class="font-medium text-primary-600 hover:underline dark:text-primary-400" x-on:click="goTo(-1)">{{ __('My OneDrive') }}</button>
                        <template x-for="(crumb, index) in trail" :key="crumb.id">
                            <span class="flex items-center gap-1">
                                <span class="text-gray-400">/</span>
                                <button type="button" class="hover:underline" dir="auto"
                                    x-bind:class="index === trail.length - 1 ? 'text-gray-950 dark:text-white' : 'font-medium text-primary-600 dark:text-primary-400'"
                                    x-text="crumb.name" x-on:click="goTo(index)"></button>
                            </span>
                        </template>
                    </nav>
                </div>

                <div class="min-h-48 flex-1 overflow-y-auto px-4 pb-4">
                    <div x-show="pickerLoading" class="flex justify-center py-10">
                        <x-filament::loading-indicator class="size-6 text-primary-600" />
                    </div>

                    <p x-show="! pickerLoading && pickerError" class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400" x-text="pickerError"></p>

                    <p x-show="! pickerLoading && ! pickerError && ! items.length" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No folders or PDF files here.') }}
                    </p>

                    <ul x-show="! pickerLoading && ! pickerError" class="divide-y divide-gray-100 dark:divide-white/5">
                        <template x-for="item in items" :key="item.id">
                            <li>
                                <button type="button" class="flex w-full items-center gap-3 px-2 py-2 text-start text-sm hover:bg-gray-50 dark:hover:bg-white/5" x-on:click="choose(item)">
                                    <span x-show="item.folder"><x-filament::icon icon="heroicon-s-folder" class="size-5 text-warning-500" /></span>
                                    <span x-show="! item.folder"><x-filament::icon icon="heroicon-o-document" class="size-5 text-danger-600" /></span>
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span class="truncate text-gray-950 dark:text-white" dir="auto" x-text="item.name"></span>
                                        <span class="truncate text-xs text-gray-500 dark:text-gray-400" dir="auto" x-show="item.where" x-text="item.where"></span>
                                    </span>
                                    <span class="shrink-0 text-xs text-gray-500 tabular-nums dark:text-gray-400" x-show="item.size !== null" x-text="item.size !== null ? sizeOf(item.size) : ''"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
