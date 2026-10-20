{{-- App\Filament\Forms\Components\RichText: TinyMCE (resources/js/rich-text-field.js). Its config is fixed while
     it is open — a change to it (a font, the letterhead, the direction) draws the editor afresh (wire:key); the
     placeholders change in place, read from the data element below as the menu opens. --}}
@php
    $statePath = $getStatePath();
    $config = $getEditorConfig();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        data-rich-text
        data-rich-text-preset="{{ $getPreset() }}"
        wire:key="{{ $getLivewireKey() }}.rich-text.{{ md5(json_encode($config)) }}"
        {{ $getExtraAttributeBag()->class(['fi-fo-rich-text']) }}
    >
        {{-- JSON with no <, > or & in it (escaped): plain text in an element Livewire updates. --}}
        <div hidden data-rich-text-live>@json($getLiveOptions())</div>

        <div
            wire:ignore
            x-load
            x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/rich-text-field.js') }}"
            x-data="richTextField({
                state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
                config: @js($config),
                statePath: @js($statePath),
                key: @js($getKey()),
                disabled: @js($isDisabled()),
            })"
            class="min-h-32"
        >
            <textarea x-ref="textarea" class="hidden" aria-hidden="true"></textarea>
        </div>
    </div>
</x-dynamic-component>
