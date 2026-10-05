{{--
    The keyboard shortcuts, announced at the top of every page for the two
    weeks after they arrived (v1.7.15); the × hides it in that browser.
--}}
@php
    $until = \Illuminate\Support\Carbon::parse('2026-10-20');
    $kbd = 'rounded border border-current/30 bg-white/60 px-1.5 py-0.5 font-mono text-xs dark:bg-white/10';
@endphp

@if (now()->lt($until))
    <div
        x-data="{ hidden: (() => { try { return localStorage.getItem('wakeel.shortcuts-tip') === '1' } catch (e) { return false } })() }"
        x-show="! hidden"
        x-cloak
        class="wakeel-banner mb-4 flex items-start gap-3 rounded-lg bg-info-50 px-4 py-3 text-sm text-info-700 ring-1 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400"
    >
        <x-filament::icon icon="heroicon-o-command-line" class="mt-0.5 h-5 w-5 shrink-0" />

        <div class="flex flex-1 flex-wrap items-center gap-x-4 gap-y-1">
            <span class="font-semibold">{{ __('New keyboard shortcuts:') }}</span>
            <span><kbd dir="ltr" class="{{ $kbd }}">Ctrl+K</kbd> {{ __('search everything') }}</span>
            <span><kbd dir="ltr" class="{{ $kbd }}">Ctrl+S</kbd> {{ __('save the form') }}</span>
            <span>
                <kbd dir="ltr" class="{{ $kbd }}">N</kbd> {{ __('or') }} <kbd dir="ltr" class="{{ $kbd }}">Ctrl+Alt+N</kbd>
                {{ filament()->getId() === 'mms' ? __('add new (on the dashboard: a new matter)') : __('add new') }}
            </span>
        </div>

        <button
            type="button"
            x-on:click="hidden = true; try { localStorage.setItem('wakeel.shortcuts-tip', '1') } catch (e) {}"
            class="shrink-0 opacity-70 hover:opacity-100"
            aria-label="{{ __('Dismiss') }}"
            title="{{ __('Dismiss') }}"
        >
            <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
        </button>
    </div>
@endif
