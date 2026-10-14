{{-- Align and bold of a text element in the page designers. $path: "elements.N". --}}
@props(['path'])
<label style="font-size: .85rem;">{{ __('Align') }}
    <x-filament::input.wrapper>
        <x-filament::input.select wire:model.live="{{ $path }}.align">
            <option value="left">{{ __('Left') }}</option>
            <option value="center">{{ __('Center') }}</option>
            <option value="right">{{ __('Right') }}</option>
        </x-filament::input.select>
    </x-filament::input.wrapper>
</label>
<label style="font-size: .85rem; display: flex; align-items: center; gap: .4rem; margin-top: 1.4rem;">
    <x-filament::input.checkbox wire:model.live="{{ $path }}.bold" />
    {{ __('Bold') }}
</label>
