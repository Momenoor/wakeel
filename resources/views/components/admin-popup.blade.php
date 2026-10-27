{{--
    A pop-up for super admins and admins that opens by itself at most once an
    hour in each browser — on any page, and also while a page stays open — and
    closes only with its own button, not a click outside or Esc. The body is
    the slot; the buttons beside Close are `actions`.
--}}
@props(['id', 'heading', 'description' => null, 'icon' => 'heroicon-o-exclamation-triangle'])

<div
    x-data="{
        key: @js($id.'-shown-at'),
        hour: 60 * 60 * 1000,
        due() {
            try { return Date.now() - Number(localStorage.getItem(this.key) || 0) >= this.hour; } catch (e) { return true; }
        },
        show() {
            if (! this.due()) { return; }
            try { localStorage.setItem(this.key, String(Date.now())); } catch (e) {}
            this.$dispatch('open-modal', { id: @js($id) });
        },
    }"
    x-init="setTimeout(() => show(), 1500); setInterval(() => show(), 60 * 1000)"
>
    <x-filament::modal
        :id="$id"
        :icon="$icon"
        icon-color="warning"
        width="xl"
        :close-by-clicking-away="false"
        :close-by-escaping="false"
        :close-button="false"
        :heading="$heading"
        :description="$description"
    >
        <div style="max-height: 50vh; overflow-y: auto;">
            {{ $slot }}
        </div>

        <x-slot name="footerActions">
            {{ $actions ?? '' }}
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: '{{ $id }}' })">
                {{ __('Close') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
