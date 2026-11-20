{{-- The user's favourites in this system, at the top of the side menu (App\Support\Favorites); drawn again when one is starred. --}}
@php
    $panel = filament()->getCurrentPanel();
    $items = $panel ? \App\Support\Favorites::items(auth()->user(), $panel, $current ?? null) : [];
@endphp
<div id="wk-favorites" class="wk-favorites">
    @if ($items !== [])
        <ul class="fi-sidebar-nav-groups">
            <x-filament-panels::sidebar.group
                :label="__('Favorites')"
                icon="heroicon-o-star"
                :items="$items"
                data-wk-favorites-group
            />
        </ul>
    @endif
</div>
