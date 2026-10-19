{{-- App\Filament\Forms\Components\SignaturePad: drawn on a white pad, kept as a transparent PNG (resources/js/signature-pad-field.js). --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-load
        x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/signature-pad-field.js') }}"
        x-data="signaturePadField({
            state: $wire.$entangle(@js($getStatePath())),
            penColor: @js($getPenColor()),
            disabled: @js($isDisabled()),
        })"
        class="flex flex-col gap-2"
    >
        <div class="relative rounded-lg border border-gray-300 bg-white dark:border-white/20" style="height: {{ $getPadHeight() }}px; touch-action: none;">
            <canvas x-ref="canvas" class="block h-full w-full rounded-lg"></canvas>
            <span x-show="empty" class="pointer-events-none absolute inset-x-0 bottom-3 text-center text-sm text-gray-400">{{ __('Sign here') }}</span>
        </div>

        @unless ($isDisabled())
            <div class="flex gap-2">
                <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-uturn-left" :loading-indicator="false" x-on:click="undo()">{{ __('Undo') }}</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-o-trash" :loading-indicator="false" x-on:click="clear()">{{ __('Clear') }}</x-filament::button>
            </div>
        @endunless
    </div>
</x-dynamic-component>
