{{--
    The PDF compressor (resources/js/pdf-compressor.js). Everything inside is drawn by Alpine, so Livewire leaves it
    alone (wire:ignore); Livewire is only asked for the user's OneDrive folders and to note the compression in the log.
--}}
@php
    $config = $this->compressorConfig();
    $card = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $levels = [
        'light' => [__('Light'), __('Barely visible: pictures kept sharp. The smallest saving.')],
        'balanced' => [__('Medium'), __('Scans stay easy to read, at about 170 dpi. Usually the best choice.')],
        'strong' => [__('Strong'), __('The smallest file: scans at about 120 dpi, still readable on screen.')],
    ];
@endphp

<x-filament-panels::page>
    <div
        wire:ignore
        x-load
        x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/pdf-compressor.js') }}"
        x-data="pdfCompressor(@js($config))"
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
                <span class="text-gray-600 dark:text-gray-400" x-show="file?.source === 'onedrive'">{{ __('From OneDrive') }}</span>
                <span class="text-gray-600 dark:text-gray-400" x-show="file?.source === 'handoff'">{{ __('From the step before') }}</span>
                {{-- This one put aside, to choose another. --}}
                <x-filament::button class="ms-auto" size="sm" color="danger" outlined icon="heroicon-o-x-mark" :loading-indicator="false" x-on:click="reset()" x-bind:disabled="busy">
                    {{ __('Discard') }}
                </x-filament::button>
            </div>

            <div x-show="! file" class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                <p>{{ __('Choose a PDF from your computer or your OneDrive, or drop it here.') }}</p>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Largest file: :size MB.', ['size' => \App\Services\MMS\PersonalOneDrive::MAX_BYTES / 1024 / 1024]) }}</span>
                {{ __('The PDF is compressed in your browser and is not uploaded: only a note that you compressed it is kept.') }}
                {{ __('Its scanned pages and pictures are saved again at a lower resolution; its text stays sharp and searchable.') }}
            </p>
        </section>

        @include('filament.mms.pages.pdf-tools.error')

        {{-- How much --}}
        <section x-show="file" x-cloak class="{{ $card }}">
            <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ __('How much to compress') }}</h3>

            <div class="grid gap-4 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('How much to compress') }}">
                @foreach ($levels as $value => [$label, $hint])
                    <div class="flex cursor-pointer flex-col gap-1 rounded-lg p-3 ring-1" x-on:click="busy || (level = '{{ $value }}')" x-bind:class="level === '{{ $value }}' ? 'ring-2 ring-primary-500' : 'ring-gray-200 dark:ring-white/10'">
                        <div class="flex items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
                            <x-filament::input.radio id="compress-{{ $value }}" name="compress-level" value="{{ $value }}" x-model="level" x-bind:disabled="busy" />
                            <label for="compress-{{ $value }}" class="cursor-pointer">{{ $label }}</label>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <x-filament::button icon="heroicon-o-arrows-pointing-in" :loading-indicator="false" x-on:click="compress()" x-bind:disabled="busy || ! file">
                    {{ __('Compress') }}
                </x-filament::button>
                <x-filament::button color="gray" :loading-indicator="false" x-show="busy && file" x-on:click="cancel()">
                    {{ __('Cancel') }}
                </x-filament::button>
            </div>
        </section>

        @include('filament.mms.pages.pdf-tools.progress', ['card' => $card])

        {{-- The compressed PDF, and the next step --}}
        <section x-show="done && ! busy" x-cloak class="{{ $card }}">
            <p class="mb-3 rounded-lg p-3 text-sm ring-1" dir="auto" x-text="summary"
                x-bind:class="done?.smaller ? 'bg-success-50 text-success-800 ring-success-600/20 dark:bg-success-400/10 dark:text-success-300' : 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400'"></p>

            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button icon="heroicon-o-arrow-down-tray" :loading-indicator="false" x-on:click="download()">
                    {{ __('Download') }}
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-scissors" :loading-indicator="false" x-show="config.next.split" x-on:click="next(config.next.split)">
                    {{ __('Then split it') }}
                </x-filament::button>
            </div>
        </section>

        @include('filament.mms.pages.pdf-tools.onedrive-picker')
    </div>
</x-filament-panels::page>
