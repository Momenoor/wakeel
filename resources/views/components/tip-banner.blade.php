{{--
    A "what's new" tip at the top of the pages until a date; the × hides it
    in that browser (remembered under `key`).
--}}
@props(['key', 'until', 'icon' => 'heroicon-o-light-bulb'])

@if (now()->lt(\Illuminate\Support\Carbon::parse($until)))
    <div
        x-data="{ hidden: (() => { try { return localStorage.getItem(@js('wakeel.tip.'.$key)) === '1' } catch (e) { return false } })() }"
        x-show="! hidden"
        x-cloak
        class="wakeel-banner mb-4 flex items-start gap-3 rounded-lg bg-info-50 px-4 py-3 text-sm text-info-700 ring-1 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400"
    >
        <x-filament::icon :icon="$icon" class="mt-0.5 h-5 w-5 shrink-0" />

        <div class="flex flex-1 flex-wrap items-center gap-x-4 gap-y-1">
            {{ $slot }}
        </div>

        <button
            type="button"
            x-on:click="hidden = true; try { localStorage.setItem(@js('wakeel.tip.'.$key), '1') } catch (e) {}"
            class="shrink-0 opacity-70 hover:opacity-100"
            aria-label="{{ __('Dismiss') }}"
            title="{{ __('Dismiss') }}"
        >
            <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
        </button>
    </div>
@endif
