@php
    use App\Support\Guide\Guide;

    $modules = $this->modules();
    $current = $this->current();
@endphp

<x-filament-panels::page>
    {{-- Self-contained styles: the panel's Tailwind build only holds the classes its own views use. --}}
    <style>
        .wk-guide { --g-bg:#fff; --g-soft:#f8fafc; --g-line:#e2e8f0; --g-text:#0f172a; --g-mute:#64748b; --g-accent:#1d4ed8; --g-accent-soft:#eff6ff; --g-ok:#047857; --g-ok-soft:#ecfdf5; --g-warn:#92400e; --g-warn-soft:#fffbeb; }
        .dark .wk-guide { --g-bg:#111827; --g-soft:#1f2937; --g-line:#374151; --g-text:#f1f5f9; --g-mute:#94a3b8; --g-accent:#60a5fa; --g-accent-soft:#1e3a5f; --g-ok:#6ee7b7; --g-ok-soft:#064e3b; --g-warn:#fcd34d; --g-warn-soft:#451a03; }
        .wk-guide { display:grid; grid-template-columns:17rem minmax(0,1fr); gap:1.25rem; align-items:start; color:var(--g-text); }
        @media (max-width: 900px) { .wk-guide { grid-template-columns:1fr; } }
        .wk-nav { position:sticky; top:5rem; display:flex; flex-direction:column; gap:.35rem; max-height:calc(100vh - 7rem); overflow-y:auto; padding-inline-end:.25rem; }
        .wk-nav h4 { margin:.85rem .5rem .1rem; font-size:.8em; font-weight:700; color:var(--g-mute); letter-spacing:.02em; }
        .wk-nav h4:first-child { margin-top:0; }
        @media (max-width: 900px) { .wk-nav { position:static; } }
        .wk-nav button { display:flex; gap:.6rem; align-items:flex-start; width:100%; text-align:start; padding:.6rem .75rem; border-radius:.6rem; border:1px solid transparent; background:transparent; color:var(--g-text); cursor:pointer; }
        .wk-nav button:hover { background:var(--g-soft); }
        .wk-nav button.on { background:var(--g-accent-soft); border-color:var(--g-accent); }
        .wk-nav svg { width:1.25rem; height:1.25rem; flex:none; margin-top:.15rem; color:var(--g-accent); }
        .wk-nav b { display:block; font-weight:700; }
        .wk-nav small { display:block; color:var(--g-mute); font-size:.85em; line-height:1.5; }
        .wk-card { background:var(--g-bg); border:1px solid var(--g-line); border-radius:.8rem; margin-bottom:1rem; overflow:hidden; }
        .wk-card > header { display:flex; justify-content:space-between; align-items:center; gap:.5rem; padding:.85rem 1.1rem; background:var(--g-soft); border-bottom:1px solid var(--g-line); cursor:pointer; }
        .wk-card > header h2 { margin:0; font-size:1.15em; font-weight:700; }
        .wk-card > .body { padding:1rem 1.1rem 1.25rem; }
        .wk-lead { line-height:2; color:var(--g-text); margin:0 0 .75rem; }
        .wk-roles { display:flex; flex-wrap:wrap; align-items:center; gap:.4rem; margin:.5rem 0 1.25rem; font-size:.85em; }
        .wk-roles .t { color:var(--g-mute); font-weight:700; }
        .wk-badge { padding:.15rem .6rem; border-radius:999px; background:var(--g-ok-soft); color:var(--g-ok); border:1px solid var(--g-ok); }
        .wk-badge.perm { background:var(--g-soft); color:var(--g-mute); border-color:var(--g-line); font-family:ui-monospace,Consolas,monospace; direction:ltr; }
        .wk-steps { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:1.5rem; counter-reset:s; }
        .wk-steps li { display:flex; gap:.8rem; }
        .wk-steps .n { flex:none; width:1.9rem; height:1.9rem; border-radius:50%; background:var(--g-accent); color:#fff; font-weight:700; display:flex; align-items:center; justify-content:center; margin-top:.15rem; }
        .wk-steps .c { flex:1; min-width:0; }
        .wk-steps p { margin:0 0 .6rem; line-height:2; }
        .wk-steps img { display:block; width:100%; border:1px solid var(--g-line); border-radius:.6rem; box-shadow:0 1px 4px rgba(15,23,42,.12); cursor:zoom-in; }
        .wk-steps pre { margin:0 0 .6rem; padding:.75rem 1rem; background:#0f172a; color:#e2e8f0; border-radius:.6rem; direction:ltr; text-align:left; overflow-x:auto; font-size:.85em; line-height:1.7; }
        .wk-tips { margin-top:1.25rem; padding:.75rem 1rem; background:var(--g-warn-soft); color:var(--g-warn); border:1px solid var(--g-warn); border-radius:.6rem; line-height:1.9; }
        .wk-tips ul { margin:.25rem 0 0; padding-inline-start:1.25rem; list-style:disc; }
        .wk-search { width:100%; padding:.55rem .8rem; border:1px solid var(--g-line); border-radius:.6rem; background:var(--g-bg); color:var(--g-text); }
        .wk-zoom { position:fixed; inset:0; z-index:60; background:rgba(2,6,23,.75); display:flex; align-items:center; justify-content:center; padding:1.5rem; cursor:zoom-out; }
        .wk-zoom img { max-width:100%; max-height:100%; border-radius:.6rem; box-shadow:0 10px 40px rgba(0,0,0,.5); }
        .wk-empty { color:var(--g-mute); padding:1rem; }
    </style>
    <div
        class="wk-guide"
        x-data="{ q: '', zoom: null, open: {}, match(el) { return this.q.trim() === '' || el.dataset.text.includes(this.q.trim().toLowerCase()) } }"
        x-on:keydown.escape.window="zoom = null"
    >
        <nav class="wk-nav">
            @foreach ($modules as $i => $m)
                @if ($i === 0 || ($modules[$i - 1]['group'] ?? null) !== ($m['group'] ?? null))
                    @isset($m['group'])<h4>{{ $m['group'] }}</h4>@endisset
                @endif
                <button type="button" wire:click="$set('module', '{{ $m['id'] }}')" @class(['on' => $m['id'] === $this->module])>
                    <x-filament::icon :icon="$m['icon']" />
                    <span>
                        <b>{{ $m['title'] }}</b>
                        <small>{{ $m['summary'] }}</small>
                    </span>
                </button>
            @endforeach
        </nav>

        <div style="min-width:0">
            @if ($current)
                <div class="wk-card">
                    <header style="cursor:default"><h2>{{ $current['title'] }}</h2></header>
                    <div class="body">
                        <p class="wk-lead">{{ $current['intro'] }}</p>
                        <input type="search" x-model="q" class="wk-search" placeholder="{{ __('Search this module…') }}" />
                    </div>
                </div>

                @foreach ($current['actions'] as $action)
                    @php
                        $text = mb_strtolower(
                            $action['title'].' '.$action['description'].' '.collect($action['steps'])->pluck('text')->implode(' ')
                        );
                    @endphp
                    <div
                        class="wk-card"
                        wire:key="{{ $this->module }}-{{ $action['id'] }}"
                        id="{{ $action['id'] }}"
                        data-text="{{ $text }}"
                        x-show="match($el)"
                        x-data="{ shown: true }"
                    >
                        <header x-on:click="shown = ! shown">
                            <h2>{{ $action['title'] }}</h2>
                            <x-filament::icon icon="heroicon-m-chevron-down" style="width:1.1rem;height:1.1rem" x-bind:style="shown ? 'transform:rotate(180deg)' : ''" />
                        </header>
                        <div class="body" x-show="shown">
                            <p class="wk-lead">{{ $action['description'] }}</p>

                            <div class="wk-roles">
                                <span class="t">{{ __('Who can do this') }}:</span>
                                @foreach ($action['roles'] as $role)
                                    <span class="wk-badge">{{ $role }}</span>
                                @endforeach
                                @foreach ($action['permissions'] ?? [] as $permission)
                                    <span class="wk-badge perm">{{ $permission }}</span>
                                @endforeach
                            </div>

                            <ol class="wk-steps">
                                @foreach ($action['steps'] as $i => $step)
                                    <li>
                                        <span class="n">{{ $i + 1 }}</span>
                                        <div class="c">
                                            <p>{{ $step['text'] }}</p>
                                            @isset($step['code'])
                                                <pre>{{ $step['code'] }}</pre>
                                            @endisset
                                            @isset($step['shot'])
                                                @php($src = Guide::shotUrl($this->module, $step['shot']))
                                                <img src="{{ $src }}" alt="{{ $step['text'] }}" loading="lazy" x-on:click="zoom = '{{ $src }}'" />
                                            @endisset
                                        </div>
                                    </li>
                                @endforeach
                            </ol>

                            @if (! empty($action['tips']))
                                <div class="wk-tips">
                                    <b>{{ __('Tips') }}</b>
                                    <ul>
                                        @foreach ($action['tips'] as $tip)
                                            <li>{{ $tip }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            @else
                <p class="wk-empty">{{ __('No guide content yet.') }}</p>
            @endif
        </div>

        <div class="wk-zoom" x-show="zoom" x-cloak x-on:click="zoom = null">
            <img :src="zoom" alt="" />
        </div>
    </div>
</x-filament-panels::page>
