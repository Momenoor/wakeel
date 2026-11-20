{{--
    Favourites (App\Support\Favorites): a star on each side-menu page (shown on hover; always, faintly, on a touch screen)
    and the page's own beside its heading. A click adds or takes out that page, and the side menu's Favorites group is
    drawn again in place.
--}}
@php
    $favoritesPanel = filament()->getCurrentPanel()?->getId();
@endphp
<style>
    .fi-sidebar-item { position: relative; }
    .wk-fav-star { position: absolute; inset-inline-end: .35rem; top: 50%; transform: translateY(-50%); display: none; padding: .25rem; border-radius: .375rem; color: rgb(156 163 175); line-height: 0; }
    .wk-fav-star svg, .wk-fav-page-star svg { width: 1rem; height: 1rem; }
    .fi-sidebar-item:hover > .wk-fav-star, .wk-fav-star:focus-visible { display: block; }
    .wk-fav-star:hover, .wk-fav-page-star:hover { color: rgb(234 179 8); background: rgb(156 163 175 / .15); }
    .wk-fav-star.wk-on, .wk-fav-page-star.wk-on { color: rgb(234 179 8); }
    .wk-fav-star .wk-fav-on, .wk-fav-page-star .wk-fav-on { display: none; }
    .wk-fav-star.wk-on .wk-fav-on, .wk-fav-page-star.wk-on .wk-fav-on { display: inline; }
    .wk-fav-star.wk-on .wk-fav-off, .wk-fav-page-star.wk-on .wk-fav-off { display: none; }
    .wk-fav-page-star { display: inline-flex; align-items: center; margin-inline-start: .5rem; padding: .3rem; border-radius: .5rem; color: rgb(156 163 175); vertical-align: middle; }
    .wk-fav-page-star svg { width: 1.25rem; height: 1.25rem; }
    .wk-favorites > .fi-sidebar-nav-groups { margin-bottom: 1.5rem; }
    /* The sidebar folded to icons: no stars. */
    .fi-sidebar:not(.fi-sidebar-open) .wk-fav-star { display: none !important; }
    @media (hover: none) { .fi-sidebar-item > .wk-fav-star { display: block; opacity: .55; } }
</style>
<script>
    (() => {
        if (window.wkFavorites) {
            return;
        }

        const panel = @js($favoritesPanel);
        const endpoint = @js(route('favorites.toggle'));
        const texts = { add: @js(__('Add to favorites')), remove: @js(__('Remove from favorites')) };
        const icons = {
            off: @js(\Filament\Support\generate_icon_html('heroicon-o-star', attributes: new \Illuminate\View\ComponentAttributeBag(['class' => 'wk-fav-off']))?->toHtml()),
            on: @js(\Filament\Support\generate_icon_html('heroicon-s-star', attributes: new \Illuminate\View\ComponentAttributeBag(['class' => 'wk-fav-on']))?->toHtml()),
        };
        let urls = new Set(@js(\App\Support\Favorites::urls(auth()->user(), (string) $favoritesPanel)));

        // An address as kept: its path and query on this site.
        const normal = (href) => {
            try {
                const url = new URL(href, location.origin);
                return url.origin === location.origin ? url.pathname + url.search : null;
            } catch (e) {
                return null;
            }
        };

        const mark = (button, on) => {
            button.classList.toggle('wk-on', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.title = on ? texts.remove : texts.add;
            button.setAttribute('aria-label', button.title);
        };

        const pageButton = () => document.querySelector('[data-wk-fav-page]');
        const pageUrl = () => location.pathname + location.search;

        // A star on each menu page of the side menu (not those of the Favorites group itself, starred already).
        const decorate = () => {
            document.querySelectorAll('.fi-sidebar-nav .fi-sidebar-item > a.fi-sidebar-item-btn').forEach((link) => {
                const item = link.parentElement;
                const url = normal(link.getAttribute('href') || '');
                if (! url) {
                    return;
                }
                let star = item.querySelector(':scope > .wk-fav-star');
                if (! star) {
                    star = document.createElement('button');
                    star.type = 'button';
                    star.className = 'wk-fav-star';
                    star.innerHTML = icons.off + icons.on;
                    star.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        toggle(url, (link.querySelector('.fi-sidebar-item-label')?.textContent || '').trim());
                    });
                    item.appendChild(star);
                }
                mark(star, urls.has(url));
            });

            const page = pageButton();
            if (page) {
                mark(page, urls.has(pageUrl()));
            }
        };

        const toggle = async (url, label) => {
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ panel, url, label, current: pageUrl() }),
                });
                const data = await response.json();
                if (! response.ok) {
                    window.alert(data.message || response.statusText);
                    return;
                }
                urls = new Set(data.urls);
                const group = document.getElementById('wk-favorites');
                if (group) {
                    group.outerHTML = data.html;
                }
                decorate();
            } catch (e) {
                console.error(e);
            }
        };

        document.addEventListener('click', (event) => {
            const page = event.target.closest('[data-wk-fav-page]');
            if (page) {
                event.preventDefault();
                const heading = document.querySelector('.fi-header-heading');
                toggle(pageUrl(), (heading?.textContent || document.title).trim());
            }
        });

        window.wkFavorites = { decorate };
        document.addEventListener('DOMContentLoaded', decorate);
        document.addEventListener('livewire:navigated', decorate);
        if (document.readyState !== 'loading') {
            decorate();
        }
    })();
</script>
