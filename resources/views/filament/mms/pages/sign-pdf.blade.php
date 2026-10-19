{{--
    The PDF editor (resources/js/pdf-signer.js). Everything inside is drawn by Alpine, so Livewire leaves it
    alone (wire:ignore). The pages are laid out left to right whatever the interface's direction: a PDF page
    has no direction, and the positions are measured from its top-left corner.
--}}
@php
    $config = $this->editorConfig();
    $libraryUrl = $this->libraryUrl();
    $isAttachment = $this->attachmentId !== null;
    $drawColors = ['#0b1f66' => __('Blue'), '#111827' => __('Black')];
@endphp

<x-filament-panels::page>
    <div
        wire:ignore
        x-load
        x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/pdf-signer.js') }}"
        x-data="pdfSigner(@js($config))"
        class="flex flex-col gap-4 lg:flex-row lg:items-start"
    >
        {{-- The library and what is selected --}}
        <aside class="flex shrink-0 flex-col gap-4 lg:sticky lg:top-20 lg:w-72">
            <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Signatures & stamps') }}</h3>
                    @if ($libraryUrl)
                        <a href="{{ $libraryUrl }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('Manage') }}</a>
                    @endif
                </div>

                <div class="flex gap-2 overflow-x-auto pb-1 lg:grid lg:grid-cols-2 lg:overflow-visible">
                    <template x-for="stamp in stamps" :key="stamp.key">
                        <button
                            type="button"
                            x-on:pointerdown="startLibraryDrag($event, () => templateFor(stamp))"
                            x-bind:title="stamp.name"
                            x-bind:disabled="! hasPdf"
                            class="flex w-28 shrink-0 touch-none flex-col items-center gap-1 rounded-lg border border-gray-200 p-2 text-center hover:border-primary-500 disabled:cursor-not-allowed disabled:opacity-50 lg:w-auto dark:border-white/10"
                            style="background: repeating-conic-gradient(rgba(127,127,127,.08) 0% 25%, transparent 0% 50%) 0 0 / 12px 12px;"
                        >
                            <img x-bind:src="stamp.url" x-bind:alt="stamp.name" draggable="false" class="pointer-events-none h-12 w-full object-contain">
                            <span x-text="stamp.name" class="w-full truncate text-xs text-gray-700 dark:text-gray-300"></span>
                        </button>
                    </template>
                </div>

                <p x-show="! stamps.length" class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('No signatures or stamps yet: add them to your library, or draw one now.') }}
                </p>

                <div class="mt-3 grid grid-cols-3 gap-2">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-pencil" :loading-indicator="false" x-on:click="openDraw()" x-bind:disabled="! hasPdf">
                        {{ __('Draw now') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-calendar" :loading-indicator="false" class="touch-none" x-on:pointerdown="startLibraryDrag($event, () => dateTemplate())" x-bind:disabled="! hasPdf">
                        {{ __('Date') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-language" :loading-indicator="false" class="touch-none" x-on:pointerdown="startLibraryDrag($event, () => textTemplate(@js(__('Text'))))" x-bind:disabled="! hasPdf">
                        {{ __('Text') }}
                    </x-filament::button>
                </div>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" x-show="hasPdf && ! armed">
                    {{ __('Tap one and then tap the page, or drag it onto the page.') }}
                </p>
                <p class="mt-3 flex items-center justify-between gap-2 text-xs font-medium text-primary-600 dark:text-primary-400" x-show="armed" x-cloak>
                    <span>{{ __('Now tap the page where it should go.') }}</span>
                    <button type="button" class="underline" x-on:click="armed = null">{{ __('Cancel') }}</button>
                </p>
            </section>

            <section x-show="selected" x-cloak class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Selected') }}</h3>

                <template x-if="selected?.kind === 'text'">
                    <div class="mb-3 flex flex-col gap-2">
                        <textarea
                            x-ref="text"
                            rows="2"
                            dir="auto"
                            class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                            x-effect="if ($el !== document.activeElement) $el.value = selected?.text ?? ''"
                            x-on:focus="remember()"
                            x-on:input="updateText($event.target.value)"
                        ></textarea>
                        <div class="flex gap-2">
                            @foreach ($drawColors as $color => $name)
                                <button type="button" class="size-6 rounded-full ring-2 ring-offset-2 dark:ring-offset-gray-900" style="background: {{ $color }}" title="{{ $name }}" aria-label="{{ $name }}"
                                    x-bind:class="selected.color === @js($color) ? 'ring-primary-500' : 'ring-transparent'"
                                    x-on:click="remember(); updateText(selected.text, @js($color))"></button>
                            @endforeach
                        </div>
                    </div>
                </template>

                <div class="flex flex-wrap gap-2">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" :loading-indicator="false" x-on:click="rotateSelected(90)">
                        {{ __('Rotate') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-document-duplicate" :loading-indicator="false" x-on:click="everyPage()" x-bind:disabled="pages.length < 2">
                        {{ __('Put on every page') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="danger" icon="heroicon-o-trash" :loading-indicator="false" x-on:click="remove()">
                        {{ __('Delete') }}
                    </x-filament::button>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Drag it to move it; the corner handle resizes it, the round handle turns it.') }}
                </p>
            </section>
        </aside>

        {{-- The document --}}
        <div class="flex min-w-0 flex-1 flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                @unless ($isAttachment)
                    <label class="fi-btn relative inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500">
                        <x-filament::icon icon="heroicon-o-folder-open" class="size-5" />
                        <span>{{ __('Choose a PDF') }}</span>
                        <input type="file" accept="application/pdf,.pdf" class="sr-only" x-on:change="pick($event)">
                    </label>
                @endunless

                <span class="min-w-0 flex-1 truncate px-2 text-sm text-gray-700 dark:text-gray-300" x-text="fileName"></span>

                <div class="flex items-center gap-1" x-show="hasPdf">
                    <x-filament::icon-button icon="heroicon-o-minus" color="gray" :label="__('Zoom out')" x-on:click="setZoom(zoom / 1.25)" />
                    <button type="button" class="w-14 text-center text-sm tabular-nums text-gray-700 dark:text-gray-300" x-on:click="fitWidth()" title="{{ __('Fit to width') }}" x-text="Math.round(zoom * 100) + '%'"></button>
                    <x-filament::icon-button icon="heroicon-o-plus" color="gray" :label="__('Zoom in')" x-on:click="setZoom(zoom * 1.25)" />
                    <x-filament::icon-button icon="heroicon-o-arrow-uturn-left" color="gray" :label="__('Undo')" x-on:click="undo()" x-bind:disabled="! history.length" />
                </div>

                <div class="flex items-center gap-2" x-show="hasPdf">
                    <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" :loading-indicator="false" x-on:click="download()" x-bind:disabled="busy">
                        {{ __('Download') }}
                    </x-filament::button>
                    @if ($config['saveUrl'])
                        <x-filament::button icon="heroicon-o-check" :loading-indicator="false" x-on:click="saveCopy()" x-bind:disabled="busy">
                            {{ __('Save signed copy') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>

            <div x-show="error" x-cloak class="flex items-start justify-between gap-2 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400">
                <span x-text="error"></span>
                <button type="button" class="font-semibold" x-on:click="error = null" aria-label="{{ __('Close') }}">&times;</button>
            </div>

            <div
                x-ref="viewer"
                dir="ltr"
                class="relative overflow-auto rounded-xl bg-gray-100 p-4 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10"
                style="height: calc(100vh - 14rem); min-height: 26rem;"
                @unless ($isAttachment)
                    x-on:dragover.prevent
                    x-on:drop.prevent="pick($event)"
                @endunless
            >
                <div x-show="loading || busy" x-cloak class="absolute inset-0 z-20 flex items-center justify-center bg-white/50 dark:bg-gray-950/50">
                    <x-filament::loading-indicator class="size-8 text-primary-600" />
                </div>

                @unless ($isAttachment)
                    <div x-show="! hasPdf && ! loading" class="flex h-full flex-col items-center justify-center gap-2 text-center text-gray-500 dark:text-gray-400" dir="auto">
                        <x-filament::icon icon="heroicon-o-document-arrow-up" class="size-12" />
                        <p class="text-sm font-medium">{{ __('Choose a PDF from your computer, or drop it here.') }}</p>
                        <p class="max-w-md text-xs">{{ __('The PDF is signed in your browser and is not uploaded: only a note that you signed it is kept.') }}</p>
                    </div>
                @endunless

                <template x-for="page in pages" :key="page.index">
                    <div
                        class="relative mx-auto mb-4 bg-white shadow-md"
                        x-bind:class="armed ? 'cursor-crosshair' : ''"
                        x-bind:data-page="page.index"
                        x-bind:style="pageStyle(page)"
                        x-init="watchPage($el)"
                        x-on:click="pageClick($event, page.index)"
                    >
                        <canvas class="pointer-events-none absolute inset-0 h-full w-full"></canvas>

                        <template x-for="item in itemsOn(page.index)" :key="item.id">
                            <div
                                class="absolute touch-none select-none"
                                x-bind:class="item.id === selectedId ? 'cursor-move outline-2 outline-offset-2 outline-primary-500 outline-dashed' : 'cursor-pointer hover:outline-1 hover:outline-primary-400 hover:outline-dashed'"
                                x-bind:style="itemStyle(item)"
                                x-on:pointerdown="startGesture($event, item, 'move')"
                                x-on:click.stop
                            >
                                <img x-bind:src="item.src" alt="" draggable="false" class="pointer-events-none block h-full w-full">

                                <template x-if="item.id === selectedId">
                                    <div>
                                        <button type="button" aria-label="{{ __('Resize') }}" title="{{ __('Resize') }}"
                                            class="absolute -right-3 -bottom-3 size-6 cursor-nwse-resize touch-none rounded-full border-2 border-white bg-primary-600 shadow"
                                            x-on:pointerdown="startGesture($event, item, 'resize')"></button>
                                        <button type="button" aria-label="{{ __('Rotate') }}" title="{{ __('Rotate') }}"
                                            class="absolute -top-9 left-1/2 flex size-6 -translate-x-1/2 cursor-grab touch-none items-center justify-center rounded-full border-2 border-white bg-primary-600 text-white shadow"
                                            x-on:pointerdown="startGesture($event, item, 'rotate')">
                                            <x-filament::icon icon="heroicon-m-arrow-path" class="pointer-events-none size-3.5" />
                                        </button>
                                        <button type="button" aria-label="{{ __('Delete') }}" title="{{ __('Delete') }}"
                                            class="absolute -top-3 -left-3 flex size-6 items-center justify-center rounded-full border-2 border-white bg-danger-600 text-white shadow"
                                            x-on:pointerdown.stop
                                            x-on:click.stop="remove(item.id)">
                                            <x-filament::icon icon="heroicon-m-x-mark" class="pointer-events-none size-3.5" />
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <span class="pointer-events-none absolute -bottom-5 left-1/2 -translate-x-1/2 text-xs text-gray-500" x-text="page.index + 1"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- What is being dragged from the library --}}
        <img x-show="dragGhost" x-cloak x-bind:src="dragGhost?.src" alt="" class="pointer-events-none fixed z-50 opacity-70" x-bind:style="ghostStyle()">

        {{-- Draw now --}}
        <div x-show="drawOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" x-on:click.self="drawOpen = false">
            <div class="w-full max-w-xl rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" x-trap.noscroll="drawOpen">
                <h3 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">{{ __('Draw now') }}</h3>

                <div class="relative h-56 rounded-lg border border-gray-300 bg-white" style="touch-action: none;">
                    <canvas x-ref="drawCanvas" class="block h-full w-full rounded-lg"></canvas>
                    <span x-show="drawEmpty" class="pointer-events-none absolute inset-x-0 bottom-3 text-center text-sm text-gray-400">{{ __('Sign here') }}</span>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @foreach ($drawColors as $color => $name)
                        <button type="button" class="size-6 rounded-full ring-2 ring-offset-2 dark:ring-offset-gray-900" style="background: {{ $color }}" title="{{ $name }}" aria-label="{{ $name }}"
                            x-bind:class="drawColor === @js($color) ? 'ring-primary-500' : 'ring-transparent'"
                            x-on:click="setDrawColor(@js($color))"></button>
                    @endforeach
                    <span class="flex-1"></span>
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-uturn-left" :loading-indicator="false" x-on:click="undoDrawing()">{{ __('Undo') }}</x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-trash" :loading-indicator="false" x-on:click="clearDrawing()">{{ __('Clear') }}</x-filament::button>
                </div>

                @if ($config['canSaveDrawn'])
                    <div class="mt-4 flex flex-col gap-2 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" class="rounded border-gray-300 text-primary-600" x-model="drawKeep">
                            {{ __('Save to my library') }}
                        </label>
                        <div x-show="drawKeep" class="grid grid-cols-2 gap-2">
                            <input type="text" maxlength="100" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white" placeholder="{{ __('Name') }}" x-model="drawName">
                            <select class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white" x-model="drawKind">
                                @foreach (\App\Enums\SignatureStampKind::cases() as $kind)
                                    <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif

                <div class="mt-5 flex justify-end gap-2">
                    <x-filament::button color="gray" :loading-indicator="false" x-on:click="drawOpen = false">{{ __('Cancel') }}</x-filament::button>
                    <x-filament::button :loading-indicator="false" x-on:click="useDrawing()" x-bind:disabled="drawEmpty || busy">{{ __('Use it') }}</x-filament::button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
