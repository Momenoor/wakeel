@props(['model'])
<label style="font-size: .85rem;">{{ __('Colour') }}
    <input type="color" wire:model.live="{{ $model }}" style="width: 100%; height: 2.25rem; border-radius: .4rem;">
</label>
