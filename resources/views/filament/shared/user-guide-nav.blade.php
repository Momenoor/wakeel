{{--
    The User Guide's list of sections (UserGuide::content() draws the rest).
    Kept as a view: Filament has no list like it — headed groups, each
    entry with a line about it, sticky beside the guide — and vertical tabs
    would draw every section's content, screenshots and all, on each page.

    The styles are self-contained: the panel's Tailwind build only holds the
    classes its own views use.
--}}
<style>
    .wk-guide { --g-bg:#fff; --g-soft:#f8fafc; --g-line:#e2e8f0; --g-text:#0f172a; --g-mute:#64748b; --g-accent:#1d4ed8; --g-accent-soft:#eff6ff; }
    .dark .wk-guide { --g-bg:#111827; --g-soft:#1f2937; --g-line:#374151; --g-text:#f1f5f9; --g-mute:#94a3b8; --g-accent:#60a5fa; --g-accent-soft:#1e3a5f; }
    .wk-nav { position:sticky; top:5rem; display:flex; flex-direction:column; gap:.35rem; max-height:calc(100vh - 7rem); overflow-y:auto; padding-inline-end:.25rem; color:var(--g-text); }
    .wk-nav h4 { margin:.85rem .5rem .1rem; font-size:.8em; font-weight:700; color:var(--g-mute); letter-spacing:.02em; }
    .wk-nav h4:first-child { margin-top:0; }
    /* On a phone the list (forty sections, each with a line about it)
       filled the screen above the guide: a dropdown instead. The breakpoint
       is the one the guide's columns stack at (lg). */
    .wk-picker { display:none; width:100%; padding:.65rem .8rem; border-radius:.6rem; border:1px solid var(--g-line); background:var(--g-bg); color:var(--g-text); font-weight:600; }
    @media (max-width: 1023px) { .wk-nav { display:none; } .wk-picker { display:block; } }
    .wk-nav button { display:flex; gap:.6rem; align-items:flex-start; width:100%; text-align:start; padding:.6rem .75rem; border-radius:.6rem; border:1px solid transparent; background:transparent; color:var(--g-text); cursor:pointer; }
    .wk-nav button:hover { background:var(--g-soft); }
    .wk-nav button.on { background:var(--g-accent-soft); border-color:var(--g-accent); }
    .wk-nav svg { width:1.25rem; height:1.25rem; flex:none; margin-top:.15rem; color:var(--g-accent); }
    .wk-nav b { display:block; font-weight:700; }
    .wk-nav small { display:block; color:var(--g-mute); font-size:.85em; line-height:1.5; }
    .wk-guide .wk-code { display:block; padding:.75rem 1rem; background:#0f172a; color:#e2e8f0; border-radius:.6rem; text-align:left; overflow-x:auto; white-space:pre; font-size:.85em; line-height:1.7; }
    .wk-guide .wk-shot { display:block; border:1px solid var(--g-line); border-radius:.6rem; box-shadow:0 1px 4px rgba(15,23,42,.12); cursor:zoom-in; }
    .wk-zoom { position:fixed; inset:0; z-index:60; background:rgba(2,6,23,.75); display:flex; align-items:center; justify-content:center; padding:1.5rem; cursor:zoom-out; }
    .wk-zoom img { max-width:100%; max-height:100%; border-radius:.6rem; box-shadow:0 10px 40px rgba(0,0,0,.5); }
</style>

<nav class="wk-nav">
    @foreach ($modules as $i => $m)
        @if ($i === 0 || ($modules[$i - 1]['group'] ?? null) !== ($m['group'] ?? null))
            @isset($m['group'])<h4>{{ $m['group'] }}</h4>@endisset
        @endif
        <button type="button" wire:click="$set('module', '{{ $m['id'] }}')" @class(['on' => $m['id'] === $module])>
            <x-filament::icon :icon="$m['icon']" />
            <span>
                <b>{{ $m['title'] }}</b>
                <small>{{ $m['summary'] }}</small>
            </span>
        </button>
    @endforeach
</nav>

<label class="sr-only" for="wk-guide-picker">{{ __('User Guide') }}</label>
<select id="wk-guide-picker" class="wk-picker" x-on:change="$wire.set('module', $event.target.value)">
    @foreach (collect($modules)->groupBy(fn ($m) => $m['group'] ?? '') as $group => $items)
        @if ($group !== '')<optgroup label="{{ $group }}">@endif
        @foreach ($items as $m)
            <option value="{{ $m['id'] }}" @selected($m['id'] === $module)>{{ $m['title'] }}</option>
        @endforeach
        @if ($group !== '')</optgroup>@endif
    @endforeach
</select>
