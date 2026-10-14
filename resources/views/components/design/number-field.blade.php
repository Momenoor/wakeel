@props(['label', 'model'])
<label style="font-size: .85rem;">{{ $label }}
    <x-filament::input.wrapper>
        <x-filament::input type="number" step="0.5" wire:model.live.debounce.400ms="{{ $model }}" />
    </x-filament::input.wrapper>
</label>
