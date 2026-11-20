{{-- The page's own star, beside its heading: this page added to, or taken out of, the favourites (favorites-script). --}}
<button
    type="button"
    class="wk-fav-page-star"
    data-wk-fav-page
    title="{{ __('Add to favorites') }}"
    aria-label="{{ __('Add to favorites') }}"
    aria-pressed="false"
>
    {{ \Filament\Support\generate_icon_html('heroicon-o-star', attributes: new \Illuminate\View\ComponentAttributeBag(['class' => 'wk-fav-off'])) }}
    {{ \Filament\Support\generate_icon_html('heroicon-s-star', attributes: new \Illuminate\View\ComponentAttributeBag(['class' => 'wk-fav-on'])) }}
</button>
