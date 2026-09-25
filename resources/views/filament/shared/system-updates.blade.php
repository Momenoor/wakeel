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
                waiting: false,
                live: '',
                poller: null,
                silentFor: null,
                failures: 0,
                lastError: null,
                gaveUp: false,
                start() {
                    if (this.running) return;
                    this.running = true;
                    this.poller = setInterval(() => this.pollLive(), 2000);
                    this.tick();
                },
                stop() {
                    this.running = false;
                    clearInterval(this.poller);
                    this.pollLive();
                },
                // The running step's output as it arrives (a plain route —
                // a Livewire call would queue behind the step itself).
                pollLive() {
                    fetch(@js(route('system-updates.live-output')), { headers: { Accept: 'application/json' } })
                        .then((response) => response.ok ? response.json() : null)
                        .then((data) => {
                            if (! data) return;
                            this.silentFor = data.silent_for;
                            if (data.output === this.live) return;
                            this.live = data.output;
                            this.$nextTick(() => { if (this.$refs.live) this.$refs.live.scrollTop = this.$refs.live.scrollHeight; });
                        })
                        .catch(() => {});
                },
                // runNextStep() runs one step (renderless), refreshState()
                // then redraws from a fresh request. A step request the web
                // server times out (a long composer install) keeps running
                // in PHP: the next call just answers 'busy' until it's done,
                // so the loop waits and checks again rather than stopping.
                tick() {
                    if ($wire.updateState === null || $wire.updateState.failed) {
                        this.stop();
                        return;
                    }
                    $wire.runNextStep()
                        .then((status) => $wire.refreshState().then(() => {
                            this.failures = 0;
                            this.waiting = status === 'busy';
                            setTimeout(() => this.tick(), this.waiting ? 3000 : 0);
                        }))
                        .catch(() => this.retryLater());
                },
                // A request that failed (a timeout PHP carries on after) is
                // retried quietly — but not forever: after 10 in a row the
                // server is answering with an error, so stop and show it.
                retryLater() {
                    this.waiting = true;
                    if (++this.failures >= 10) {
                        this.gaveUp = true;
                        this.stop();
                        return;
                    }
                    setTimeout(() => $wire.refreshState().then(() => this.tick(), () => this.retryLater()), 3000);
                },
                // Handle this page's failed requests here instead of Livewire's
                // error pop-up (the dark overlay that closed on any click). A
                // global request interceptor filtered by this component's id:
                // $wire.$intercept() never matches the request in this
                // Livewire version. Once per component — this runs again when
                // the page redraws. (A method, not x-init code: a comment-first
                // x-init isn't recognised as statements by Alpine, fails to
                // parse, and the update loop never started.)
                handleErrorsHere() {
                    const id = $wire.$id;
                    window.__noErrorPopup ??= new Set();
                    if (window.__noErrorPopup.has(id)) return;
                    window.__noErrorPopup.add(id);
                    Livewire.interceptRequest(({ request, onError }) => {
                        if (! Array.from(request.messages).some((message) => message.component.id === id)) return;
                        onError(({ preventDefault, response, body }) => {
                            preventDefault();
                            // Kept for the page to show if failures persist.
                            const text = new DOMParser().parseFromString(body || '', 'text/html').body.textContent || '';
                            window.dispatchEvent(new CustomEvent('updater-request-error', { detail: {
                                status: response?.status,
                                text: text.replace(/\s+/g, ' ').trim().slice(0, 300),
                            } }));
                        });
                    });
                },
            }"
            x-on:updater-request-error.window="lastError = $event.detail"
            x-init="handleErrorsHere(); start()"
        >
            <div x-show="waiting && ! gaveUp" x-cloak class="mb-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400">
                {{ __('Waiting for the server to finish this step… This can take a few minutes. If nothing changes for a long time, reload the page — the update resumes where it stopped.') }}
                <span x-show="silentFor !== null && silentFor >= 60" x-text="@js(__('No output for :minutes min.')).replace(':minutes', Math.floor(silentFor / 60))"></span>
                <span x-show="silentFor !== null && silentFor >= 60">{{ __('After :minutes minutes without output the step is treated as stuck and restarted.', ['minutes' => intdiv(\App\Services\Updater\Updater::STALE_AFTER, 60)]) }}</span>
            </div>

            <div x-show="gaveUp" x-cloak class="mb-4 rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400">
                {{ __('The server kept answering with an error, so the update loop has stopped. Reload the page to try again; the update resumes where it stopped.') }}
                <template x-if="lastError">
                    <div class="mt-2 font-mono text-xs" x-text="'HTTP ' + (lastError.status ?? '?') + (lastError.text ? ' — ' + lastError.text : '')"></div>
                </template>
            </div>

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

            {{-- wire:ignore: Livewire redraws must not reset what polling filled in. --}}
            <div wire:ignore x-show="running && live !== ''" x-cloak class="mt-6">
                <x-filament::section :heading="__('Live output')">
                    <pre x-ref="live" x-text="live" class="max-h-96 overflow-auto whitespace-pre-wrap text-xs"></pre>
                </x-filament::section>
            </div>

            @if (filled($state['log']))
                <x-filament::section :heading="__('Log')" collapsible>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap text-xs">{{ $state['log'] }}</pre>
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
