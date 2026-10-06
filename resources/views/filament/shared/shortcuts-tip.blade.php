{{--
    The keyboard shortcuts (v1.7.15) and the User Guide, announced at the top
    of every page for two weeks.
--}}
@php
    $kbd = 'rounded border border-current/30 bg-white/60 px-1.5 py-0.5 font-mono text-xs dark:bg-white/10';
@endphp

<x-tip-banner key="shortcuts" until="2026-10-20" icon="heroicon-o-command-line">
    <span class="font-semibold">{{ __('New keyboard shortcuts:') }}</span>
    <span><kbd dir="ltr" class="{{ $kbd }}">Ctrl+K</kbd> {{ __('search everything') }}</span>
    <span><kbd dir="ltr" class="{{ $kbd }}">Ctrl+S</kbd> {{ __('save the form') }}</span>
    <span>
        <kbd dir="ltr" class="{{ $kbd }}">N</kbd> {{ __('or') }} <kbd dir="ltr" class="{{ $kbd }}">Ctrl+Alt+N</kbd>
        {{ filament()->getId() === 'mms' ? __('add new (on the dashboard: a new matter)') : __('add new') }}
    </span>
    @if (\App\Support\AppUpdate::canManage())
        <span><kbd dir="ltr" class="{{ $kbd }}">Ctrl+Alt+U</kbd> {{ __('System Updates') }}</span>
    @endif
</x-tip-banner>

@if (\App\Filament\Shared\Pages\UserGuide::canAccess() && ! request()->routeIs('filament.*.pages.user-guide'))
    <x-tip-banner key="user-guide" until="2026-10-20" icon="heroicon-o-book-open">
        <span class="font-semibold">{{ __('Discover the User Guide') }}</span>
        <span>{{ __('A section that explains every screen and action in the system, step by step with pictures — and who can do each one.') }}</span>
        <a href="{{ \App\Filament\Shared\Pages\UserGuide::getUrl() }}" class="font-semibold underline">{{ __('Open the guide') }}</a>
    </x-tip-banner>
@endif
