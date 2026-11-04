{{--
    The PDF merger (resources/js/pdf-merger.js). Everything inside is drawn by Alpine, so Livewire leaves it alone
    (wire:ignore); Livewire is only asked for the user's OneDrive folders and to note the merge in the log.
--}}
@php
    $config = $this->mergerConfig();
    $card = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $chooser = 'fi-btn relative inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold';
@endphp

<x-filament-panels::page>
    <div
        wire:ignore
        x-load
        x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/pdf-merger.js') }}"
        x-data="pdfMerger(@js($config))"
        class="flex flex-col gap-4"
    >
        {{-- The files --}}
        <section class="{{ $card }}" x-on:dragover.prevent x-on:drop.prevent="pick($event)">
            <div class="flex flex-wrap items-center gap-2">
                <label class="{{ $chooser }} bg-primary-600 text-white hover:bg-primary-500" x-bind:class="busy && 'pointer-events-none opacity-50'">
                    <x-filament::icon icon="heroicon-o-document-plus" class="size-5" />
                    <span>{{ __('Choose PDFs') }}</span>
                    <input type="file" accept="application/pdf,.pdf" multiple class="sr-only" x-on:change="pick($event)" x-bind:disabled="busy">
                </label>

                {{-- A whole folder: its PDFs (and its sub-folders') in the order of their names. --}}
                <label class="{{ $chooser }} bg-white text-gray-950 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20" x-bind:class="busy && 'pointer-events-none opacity-50'">
                    <x-filament::icon icon="heroicon-o-folder-open" class="size-5" />
                    <span>{{ __('Choose a folder') }}</span>
                    <input type="file" webkitdirectory directory multiple class="sr-only" x-on:change="pick($event, true)" x-bind:disabled="busy">
                </label>

                <x-filament::button color="gray" icon="heroicon-o-cloud" :loading-indicator="false" x-on:click="openPicker()" x-bind:disabled="busy">
                    {{ __('From OneDrive') }}
                </x-filament::button>
            </div>

            <div x-show="! files.length" class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                <p>{{ __('Choose the PDFs to merge — several files, or a folder to merge every PDF in it in the order of their names — from your computer or your OneDrive, or drop them here.') }}</p>
            </div>

            {{-- In the order they will be merged: moved up and down, or taken out. --}}
            <div x-show="files.length" x-cloak class="mt-4">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="font-medium text-gray-950 dark:text-white">
                        {{ __('Files') }}: <span class="tabular-nums" x-text="files.length"></span>
                        <span class="text-gray-500 dark:text-gray-400" x-show="totalSize">— <span x-text="sizeOf(totalSize)"></span></span>
                    </span>
                    <span class="flex gap-2">
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-bars-arrow-down" :loading-indicator="false" x-on:click="sortByName()" x-bind:disabled="busy">
                            {{ __('Sort by name') }}
                        </x-filament::button>
                        <x-filament::button size="sm" color="danger" outlined icon="heroicon-o-x-mark" :loading-indicator="false" x-on:click="clear()" x-bind:disabled="busy">
                            {{ __('Discard all') }}
                        </x-filament::button>
                    </span>
                </div>

                <ol class="divide-y divide-gray-100 rounded-lg ring-1 ring-gray-950/5 dark:divide-white/5 dark:ring-white/10">
                    <template x-for="(file, index) in files" :key="file.id">
                        <li class="flex items-center gap-2 px-3 py-2 text-sm">
                            <span class="w-6 shrink-0 text-end text-gray-400 tabular-nums" x-text="index + 1"></span>
                            <x-filament::icon icon="heroicon-o-document" class="size-5 shrink-0 text-danger-600" />
                            <span class="min-w-0 flex-1 truncate text-gray-950 dark:text-white" dir="auto" x-text="file.name"></span>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" x-show="file.source === 'onedrive'">OneDrive</span>
                            <span class="shrink-0 text-xs text-gray-500 tabular-nums dark:text-gray-400" x-text="sizeOf(file.size)"></span>
                            <x-filament::icon-button icon="heroicon-o-chevron-up" color="gray" size="sm" :label="__('Move up')" x-on:click="move(index, -1)" x-bind:disabled="busy || index === 0" />
                            <x-filament::icon-button icon="heroicon-o-chevron-down" color="gray" size="sm" :label="__('Move down')" x-on:click="move(index, 1)" x-bind:disabled="busy || index === files.length - 1" />
                            <x-filament::icon-button icon="heroicon-o-x-mark" color="danger" size="sm" :label="__('Discard')" x-on:click="remove(index)" x-bind:disabled="busy" />
                        </li>
                    </template>
                </ol>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Largest file: :size MB.', ['size' => \App\Services\MMS\PersonalOneDrive::MAX_BYTES / 1024 / 1024]) }}</span>
                {{ __('The PDFs are merged in your browser and are not uploaded: only a note that you merged them is kept.') }}
            </p>
        </section>

        @include('filament.mms.pages.pdf-tools.error')

        {{-- Merging --}}
        <section x-show="files.length" x-cloak class="{{ $card }}">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-filament::input.wrapper :prefix="__('Name')" suffix=".pdf" alpine-disabled="busy">
                    <x-filament::input type="text" autocomplete="off" dir="auto" :placeholder="__('Merged')" x-model="outputName" x-bind:disabled="busy" />
                </x-filament::input.wrapper>

                <label class="flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                    <x-filament::input.checkbox x-model="bookmarks" x-bind:disabled="busy" />
                    <span>{{ __('A bookmark for each file') }}</span>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <x-filament::button icon="heroicon-o-document-duplicate" :loading-indicator="false" x-on:click="merge()" x-bind:disabled="busy || files.length < 2">
                    {{ __('Merge') }}
                </x-filament::button>
                <x-filament::button color="gray" :loading-indicator="false" x-show="busy" x-on:click="cancel()">
                    {{ __('Cancel') }}
                </x-filament::button>
            </div>
        </section>

        @include('filament.mms.pages.pdf-tools.progress', ['card' => $card])

        {{-- The merged PDF, and the next steps --}}
        <section x-show="merged && ! busy" x-cloak class="{{ $card }}">
            <p class="mb-3 rounded-lg bg-success-50 p-3 text-sm text-success-800 ring-1 ring-success-600/20 dark:bg-success-400/10 dark:text-success-300" dir="auto" x-text="summary"></p>

            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button icon="heroicon-o-arrow-down-tray" :loading-indicator="false" x-on:click="download()">
                    {{ __('Download') }}
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-arrows-pointing-in" :loading-indicator="false" x-show="config.next.compress" x-on:click="next(config.next.compress)">
                    {{ __('Then compress it') }}
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-scissors" :loading-indicator="false" x-show="config.next.split" x-on:click="next(config.next.split)">
                    {{ __('Then split it') }}
                </x-filament::button>
            </div>
        </section>

        @include('filament.mms.pages.pdf-tools.onedrive-picker')
    </div>
</x-filament-panels::page>
