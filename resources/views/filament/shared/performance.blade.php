@php
    $stats = $this->stats();
    $screens = $this->screens();
    $slowest = $this->slowest();
    $slow = \App\Http\Middleware\TrackPerformance::SLOW_MS;
    $tone = fn (float $ms): string => $ms >= $slow ? 'color: rgb(220 38 38); font-weight: 700;' : ($ms >= $slow / 2 ? 'color: rgb(217 119 6); font-weight: 600;' : '');
@endphp

<x-filament-panels::page>
    <style>
        .wk-perf-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: .75rem; }
        .wk-perf-card { padding: .9rem 1rem; border-radius: .75rem; background: var(--color-white, #fff); border: 1px solid rgba(3, 7, 18, .08); }
        .dark .wk-perf-card { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .1); }
        .wk-perf-card small { display: block; font-size: .75rem; color: rgb(107 114 128); }
        .wk-perf-card b { display: block; font-size: 1.4rem; margin-top: .15rem; }
        .wk-perf-table { width: 100%; font-size: .82rem; border-collapse: collapse; }
        .wk-perf-table th, .wk-perf-table td { padding: .45rem .6rem; border-bottom: 1px solid rgba(3, 7, 18, .06); text-align: start; vertical-align: top; }
        .dark .wk-perf-table th, .dark .wk-perf-table td { border-color: rgba(255, 255, 255, .08); }
        .wk-perf-table th { font-weight: 600; color: rgb(107 114 128); white-space: nowrap; }
        .wk-perf-table td.n, .wk-perf-table th.n { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .wk-perf-table th button { font-weight: 600; }
        .wk-perf-table th button.on { color: rgb(37 99 235); }
        .wk-perf-sql { font-family: ui-monospace, monospace; font-size: .72rem; color: rgb(107 114 128); word-break: break-all; }
        .wk-perf-wrap { overflow-x: auto; }
    </style>

    {{-- The period, or a check's results. --}}
    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: .75rem;">
        @if ($check)
            <x-filament::badge color="info">{{ __('Results of the check of every screen') }}</x-filament::badge>
            <x-filament::link tag="button" wire:click="showPeriod">{{ __('Back to all requests') }}</x-filament::link>
        @else
            <x-filament::input.wrapper style="width: 12rem;">
                <x-filament::input.select wire:model.live="period">
                    <option value="24h">{{ __('Last 24 hours') }}</option>
                    <option value="7d">{{ __('Last 7 days') }}</option>
                    <option value="14d">{{ __('Last 14 days') }}</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        @endif

        @unless ($this->tracking())
            <x-filament::badge color="warning">{{ __('Measuring is stopped') }}</x-filament::badge>
        @endunless

        {{-- Each screen opened in turn, from here, as you: on this server, with its data. --}}
        <div
            x-data="{
                total: 0,
                done: 0,
                running: false,
                async run() {
                    this.running = true;
                    const id = await $wire.startCheck();
                    const urls = await $wire.checkUrls();
                    this.total = urls.length;
                    this.done = 0;
                    for (const url of urls) {
                        try { await fetch(url, { headers: { @js(\App\Http\Middleware\TrackPerformance::CHECK_HEADER): id }, credentials: 'same-origin' }); } catch (e) {}
                        this.done++;
                    }
                    this.running = false;
                    await $wire.showCheck(id);
                },
            }"
            style="margin-inline-start: auto; display: flex; align-items: center; gap: .75rem;"
        >
            <span x-show="running" x-cloak style="font-size: .85rem;">{{ __('Opening screen') }} <span x-text="done"></span> / <span x-text="total"></span></span>
            <x-filament::button icon="heroicon-o-play-circle" x-on:click="run()" x-bind:disabled="running">
                {{ __('Check every screen') }}
            </x-filament::button>
        </div>
    </div>

    <div class="wk-perf-cards">
        <div class="wk-perf-card"><small>{{ __('Requests') }}</small><b>{{ number_format($stats['requests']) }}</b></div>
        <div class="wk-perf-card"><small>{{ __('Average time') }}</small><b style="{{ $tone($stats['avg']) }}">{{ number_format($stats['avg']) }} ms</b></div>
        <div class="wk-perf-card"><small>{{ __('95% of requests within') }}</small><b style="{{ $tone($stats['p95']) }}">{{ number_format($stats['p95']) }} ms</b></div>
        <div class="wk-perf-card"><small>{{ __('Slow requests (1 s or more)') }}</small><b style="{{ $stats['slow'] ? 'color: rgb(220 38 38);' : '' }}">{{ number_format($stats['slow']) }}</b></div>
        <div class="wk-perf-card"><small>{{ __('Database queries per request') }}</small><b>{{ $stats['queries'] }}</b></div>
    </div>

    <x-filament::section :heading="__('By screen')" :description="__('Repeated: the same query run again in one request — usually once per row of a list, the first thing to fix.')">
        @if ($screens->isEmpty())
            <p style="font-size: .85rem; color: rgb(107 114 128);">{{ __('Nothing measured yet.') }}</p>
        @else
            <div class="wk-perf-wrap">
                <table class="wk-perf-table">
                    <thead>
                        <tr>
                            <th>{{ __('Screen or action') }}</th>
                            @foreach (['count' => __('Requests'), 'avg' => __('Average ms'), 'max' => __('Slowest ms'), 'queries' => __('Queries'), 'repeated' => __('Repeated')] as $key => $label)
                                <th class="n"><button type="button" wire:click="sortBy('{{ $key }}')" @class(['on' => $sort === $key])>{{ $label }}</button></th>
                            @endforeach
                            <th class="n">{{ __('Memory MB') }}</th>
                            <th class="n">{{ __('Size KB') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($screens as $row)
                            <tr>
                                <td dir="ltr" style="text-align: start;">{{ $row->name }}</td>
                                <td class="n">{{ number_format($row->requests) }}</td>
                                <td class="n" style="{{ $tone((float) $row->avg_ms) }}">{{ number_format((float) $row->avg_ms) }}</td>
                                <td class="n" style="{{ $tone((float) $row->max_ms) }}">{{ number_format((float) $row->max_ms) }}</td>
                                <td class="n">{{ round((float) $row->avg_queries) }} <small style="color: rgb(156 163 175);">/ {{ $row->max_queries }}</small></td>
                                <td class="n" style="{{ $row->avg_repeated >= 10 ? 'color: rgb(220 38 38); font-weight: 700;' : '' }}">{{ round((float) $row->avg_repeated) }}</td>
                                <td class="n">{{ round((float) $row->avg_mb, 1) }}</td>
                                <td class="n">{{ number_format((float) $row->avg_kb) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('Slowest requests')" collapsible>
        @if ($slowest->isEmpty())
            <p style="font-size: .85rem; color: rgb(107 114 128);">{{ __('Nothing measured yet.') }}</p>
        @else
            <div class="wk-perf-wrap">
                <table class="wk-perf-table">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('User') }}</th>
                            <th>{{ __('Screen or action') }}</th>
                            <th class="n">ms</th>
                            <th class="n">{{ __('Queries') }}</th>
                            <th class="n">{{ __('Repeated') }}</th>
                            <th class="n">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($slowest as $sample)
                            <tr>
                                <td style="white-space: nowrap;">{{ $sample->created_at->diffForHumans() }}</td>
                                <td>{{ $sample->user?->display_name ?: $sample->user?->name ?: '—' }}</td>
                                <td dir="ltr" style="text-align: start;">
                                    {{ $sample->name }}
                                    @if ($sample->top_query)
                                        <div class="wk-perf-sql">{{ $sample->top_query }}</div>
                                    @endif
                                </td>
                                <td class="n" style="{{ $tone($sample->duration_ms) }}">{{ number_format($sample->duration_ms) }}</td>
                                <td class="n">{{ $sample->queries }}</td>
                                <td class="n">{{ $sample->repeated }}</td>
                                <td class="n">{{ $sample->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
