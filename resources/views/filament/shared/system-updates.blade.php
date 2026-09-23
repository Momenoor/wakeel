@php
    $updater = app(\App\Services\Updater\Updater::class);
    $state = $this->updateState;
    $steps = $updater->steps();
    $current = \App\Support\AppUpdate::currentVersion();
    $latest = $this->license?->latest_version;
    $newer = $latest !== null && version_compare($latest, $current, '>');
@endphp

<x-filament-panels::page>
    @if ($state === null)
        <x-filament::section :heading="__('Version')">
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Installed version') }}</dt>
                    <dd class="text-lg font-semibold">{{ $current }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Latest version') }}</dt>
                    <dd class="text-lg font-semibold">
                        {{ $latest ?? '—' }}
                        @if ($newer)
                            <x-filament::badge color="warning" class="ms-1 inline-flex">{{ __('Update available') }}</x-filament::badge>
                        @elseif ($latest !== null)
                            <x-filament::badge color="success" class="ms-1 inline-flex">{{ __('Up to date') }}</x-filament::badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Last checked') }}</dt>
                    <dd class="text-lg font-semibold">{{ $this->license?->last_checked_at?->diffForHumans() ?? '—' }}</dd>
                </div>
            </dl>
        </x-filament::section>

        @if ($newer && filled($this->license?->latest_release_notes))
            <x-filament::section :heading="__('What\'s new in :version', ['version' => $latest])">
                <div class="prose max-w-none dark:prose-invert">
                    {!! \Illuminate\Support\Str::markdown($this->license->latest_release_notes, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                </div>
            </x-filament::section>
        @endif
    @else
        <div
            x-data="{
                running: false,
                start() {
                    if (this.running) return;
                    this.running = true;
                    this.tick();
                },
                tick() {
                    if ($wire.updateState === null || $wire.updateState.failed) {
                        this.running = false;
                        return;
                    }
                    $wire.runNextStep().then(() => this.tick(), () => { this.running = false; });
                },
            }"
            x-init="start()"
        >
            <x-filament::section :heading="__('Updating to :version', ['version' => $state['version']])">
                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @php $pendingShown = false; @endphp
                    @foreach ($steps as $key => $label)
                        @php
                            $done = in_array($key, $state['completed'], true);
                            $isCurrent = ! $done && ! $pendingShown;
                            if ($isCurrent) { $pendingShown = true; }
                        @endphp
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span @class(['text-gray-400 dark:text-gray-500' => ! $done && ! $isCurrent])>{{ $label }}</span>
                            @if ($done)
                                <x-filament::badge color="success">{{ __('Done') }}</x-filament::badge>
                            @elseif ($isCurrent && $state['failed'])
                                <x-filament::badge color="danger">{{ __('Failed') }}</x-filament::badge>
                            @elseif ($isCurrent)
                                <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                            @else
                                <x-filament::badge color="gray">{{ __('Pending') }}</x-filament::badge>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($state['failed'])
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-filament::button x-on:click="$wire.retryStep().then(() => start())">
                            {{ __('Retry failed step') }}
                        </x-filament::button>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('The site stays in maintenance mode until the update completes or is cancelled from the button at the top of this page.') }}
                    </p>
                @endif
            </x-filament::section>

            @if (filled($state['log']))
                <x-filament::section :heading="__('Log')" collapsible>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap text-xs">{{ $state['log'] }}</pre>
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
