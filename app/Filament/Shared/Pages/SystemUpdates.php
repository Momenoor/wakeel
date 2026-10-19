<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Concerns\RendersFreshContent;
use App\Filament\Shared\Clusters\Settings;
use App\Models\License;
use App\Services\License\LicenseVerifier;
use App\Services\Updater\Updater;
use App\Support\AppUpdate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Attributes\Renderless;

use function Filament\Support\generate_loading_indicator_html;

/**
 * Shows this installation's version against the newest release and runs
 * the one-click update (see {@see Updater}) — one step per Livewire call,
 * chained from the view, so each step's result shows as it lands and no
 * single request has to outlive the whole update.
 */
class SystemUpdates extends Page
{
    use RendersFreshContent;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowPathRoundedSquare;

    protected static ?int $navigationSort = 63;

    protected static ?string $cluster = Settings::class;

    public static function getNavigationLabel(): string
    {
        return __('System Updates');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Maintenance');
    }

    public function getTitle(): string
    {
        return __('System Updates');
    }

    public static function canAccess(): bool
    {
        return AppUpdate::canManage();
    }

    public static function getNavigationBadge(): ?string
    {
        return AppUpdate::available() ? __('New') : null;
    }

    /**
     * The running (or failed) update, if any — public so the view's
     * step loop can read it. Null when no update is in progress.
     *
     * @var array{version: string, completed: list<string>, failed: bool, log: string}|null
     */
    public ?array $updateState = null;

    public function mount(): void
    {
        $this->updateState = app(Updater::class)->state();
    }

    public function getLicenseProperty(): ?License
    {
        return License::current();
    }

    /**
     * Called repeatedly by the view while an update is running, followed
     * each time by refreshState().
     *
     * Renderless: after the dependencies step replaces vendor/, this same
     * PHP process must not go on to render the page — loading classes from
     * a vendor/ swapped out underneath it can crash the response. The
     * fresh refreshState() request renders instead.
     *
     * @return 'ran'|'stopped'|'busy'
     */
    #[Renderless]
    public function runNextStep(): string
    {
        return app(Updater::class)->runNextStepIfIdle();
    }

    public function refreshState(): void
    {
        $this->updateState = app(Updater::class)->state();
    }

    public function retryStep(): void
    {
        app(Updater::class)->retry();
        $this->updateState = app(Updater::class)->state();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components($this->updateState === null ? $this->versionComponents() : $this->progressComponents());
    }

    /**
     * No update running: this version against the newest, and what is new
     * in it.
     *
     * @return array<Component>
     */
    private function versionComponents(): array
    {
        $latest = $this->license?->latest_version;
        $notes = $this->license?->latest_release_notes;

        return [
            Section::make(__('Version'))
                ->columns(['sm' => 3])
                ->schema([
                    $this->versionEntry('installed', __('Installed version'), AppUpdate::currentVersion()),

                    $this->versionEntry('latest', __('Latest version'), $latest ?? '—')
                        ->afterContent(match (true) {
                            $this->latestIsNewer() => self::badge(__('Update available'), 'warning'),
                            $latest !== null => self::badge(__('Up to date'), 'success'),
                            default => null,
                        }),

                    $this->versionEntry('last_checked', __('Last checked'), $this->license?->last_checked_at?->diffForHumans() ?? '—'),
                ]),

            Section::make(__('What\'s new in :version', ['version' => $latest]))
                ->visible($this->latestIsNewer() && filled($notes))
                ->schema([
                    TextEntry::make('release_notes')
                        ->hiddenLabel()
                        ->state(fn (): HtmlString => new HtmlString(Str::markdown((string) $notes, ['html_input' => 'escape', 'allow_unsafe_links' => false])))
                        ->prose(),
                ]),
        ];
    }

    /**
     * A badge beside other content that never shrinks: next to a full-width entry (or a step's name) Filament
     * let it shrink to its minimum, and its label was cut off.
     */
    private static function badge(string $label, string $color): Text
    {
        return Text::make($label)->badge()->color($color)->extraAttributes(['style' => 'flex-shrink: 0;']);
    }

    private function versionEntry(string $name, string $label, string $value): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state($value)
            ->size(TextSize::Large)
            ->weight(FontWeight::SemiBold);
    }

    /**
     * An update running (or failed): its steps as they land, the running
     * step's output, and the log. The step loop itself runs in the browser
     * — see updateLoopAlpine().
     *
     * @return array<Component>
     */
    private function progressComponents(): array
    {
        $state = $this->updateState;

        return [
            Group::make([
                Callout::make()
                    ->description(__('Waiting for the server to finish this step… This can take a few minutes. If nothing changes for a long time, reload the page — the update resumes where it stopped.'))
                    ->color('warning')
                    ->footer([
                        Text::make('')->extraAttributes([
                            'x-show' => 'silentFor !== null && silentFor >= 60',
                            'x-text' => Js::from(__('No output for :minutes min.')).".replace(':minutes', Math.floor(silentFor / 60))",
                        ]),
                        Text::make(__('After :minutes minutes without output the step is treated as stuck and restarted.', ['minutes' => intdiv(Updater::STALE_AFTER, 60)]))
                            ->extraAttributes(['x-show' => 'silentFor !== null && silentFor >= 60']),
                    ])
                    ->extraAttributes(['x-show' => 'waiting && ! gaveUp', 'x-cloak' => true]),

                Callout::make()
                    ->description(__('The server kept answering with an error, so the update loop has stopped. Reload the page to try again; the update resumes where it stopped.'))
                    ->color('danger')
                    ->footer([
                        Text::make('')
                            ->fontFamily(FontFamily::Mono)
                            ->size(TextSize::ExtraSmall)
                            ->extraAttributes([
                                'x-show' => 'lastError',
                                'x-text' => "lastError ? 'HTTP ' + (lastError.status ?? '?') + (lastError.text ? ' — ' + lastError.text : '') : ''",
                            ]),
                    ])
                    ->extraAttributes(['x-show' => 'gaveUp', 'x-cloak' => true]),

                Section::make(__('Updating to :version', ['version' => $state['version']]))
                    ->divided()
                    ->schema([
                        ...$this->stepRows($state),

                        Group::make([
                            Actions::make([
                                Action::make('retryStep')
                                    ->label(__('Retry failed step'))
                                    ->alpineClickHandler('$wire.retryStep().then(() => start())'),
                            ]),
                            Text::make(__('The site stays in maintenance mode until the update completes or is cancelled from the button at the top of this page.')),
                        ])->visible($state['failed']),
                    ]),

                // wire:ignore: Livewire redraws must not reset what polling filled in.
                Section::make(__('Live output'))
                    ->schema([
                        Text::make('')
                            ->fontFamily(FontFamily::Mono)
                            ->size(TextSize::ExtraSmall)
                            ->extraAttributes(['data-updater-live' => true, 'x-text' => 'live', 'class' => 'block max-h-96 overflow-auto whitespace-pre-wrap']),
                    ])
                    ->extraAttributes(['wire:ignore' => true, 'x-show' => "running && live !== ''", 'x-cloak' => true]),

                Section::make(__('Log'))
                    ->collapsible()
                    ->visible(filled($state['log']))
                    ->schema([
                        Html::make(new HtmlString('<pre class="block max-h-96 overflow-auto whitespace-pre-wrap font-mono text-xs">'.e((string) $state['log']).'</pre>')),
                    ]),
            ])->extraAttributes([
                'x-data' => $this->updateLoopAlpine(),
                'x-on:updater-request-error.window' => 'lastError = $event.detail',
                'x-init' => 'handleErrorsHere(); start()',
            ]),
        ];
    }

    /**
     * Each step with how it stands: done, running (the first not done),
     * failed, or still to come.
     *
     * @param  array{version: string, completed: list<string>, failed: bool, log: string}  $state
     * @return list<Flex>
     */
    private function stepRows(array $state): array
    {
        $rows = [];
        $pendingShown = false;

        foreach (app(Updater::class)->steps() as $key => $label) {
            $done = in_array($key, $state['completed'], true);
            $isCurrent = ! $done && ! $pendingShown;
            $pendingShown = $pendingShown || $isCurrent;

            $rows[] = Flex::make([
                Text::make($label)->weight(($done || $isCurrent) ? FontWeight::Medium : null),
                match (true) {
                    $done => self::badge(__('Done'), 'success'),
                    $isCurrent && $state['failed'] => self::badge(__('Failed'), 'danger'),
                    $isCurrent => Html::make(generate_loading_indicator_html(new ComponentAttributeBag(['class' => 'h-5 w-5 text-primary-500']))),
                    default => self::badge(__('Pending'), 'gray'),
                },
            ])->verticallyAlignCenter()->alignBetween();
        }

        return $rows;
    }

    /**
     * The update loop, in the browser: one step per Livewire call, so each
     * step's result shows as it lands and no single request has to outlive
     * the whole update. Single quotes only — it sits in an HTML attribute.
     */
    private function updateLoopAlpine(): string
    {
        return str_replace('__LIVE_OUTPUT_URL__', Js::from(route('system-updates.live-output'))->toHtml(), <<<'JS'
            {
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
                    fetch(__LIVE_OUTPUT_URL__, { headers: { Accept: 'application/json' } })
                        .then((response) => response.ok ? response.json() : null)
                        .then((data) => {
                            if (! data) return;
                            this.silentFor = data.silent_for;
                            if (data.output === this.live) return;
                            this.live = data.output;
                            // Found by attribute, not x-ref: the schema's own wrappers are
                            // Alpine components, so a ref inside them is not this one's.
                            this.$nextTick(() => { const box = this.$root.querySelector('[data-updater-live]'); if (box) box.scrollTop = box.scrollHeight; });
                        })
                        .catch(() => {});
                },
                // runNextStep() runs one step (renderless), refreshState()
                // then redraws from a fresh request. A step request the web
                // server times out (a long composer install) keeps running
                // in PHP: the next call just answers 'busy' until it is done,
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
                // x-init is not recognised as statements by Alpine, fails to
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
            }
            JS);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkNow')
                ->label(__('Check for updates'))
                ->icon(Heroicon::ArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => $this->updateState !== null)
                ->action(function (): void {
                    $license = License::current();

                    if ($license === null) {
                        Notification::make()->title(__('No license on file.'))->danger()->send();

                        return;
                    }

                    $result = app(LicenseVerifier::class)->verify($license);
                    $latest = $result['latest_version'];

                    $title = match (true) {
                        ! $result['valid'] => __('Could not check: :reason', ['reason' => (string) $result['reason']]),
                        $latest !== null && version_compare($latest, AppUpdate::currentVersion(), '>') => __('Version :version is available.', ['version' => $latest]),
                        default => __('You are on the latest version.'),
                    };

                    Notification::make()
                        ->title($title)
                        ->color($result['valid'] ? 'success' : 'danger')
                        ->send();
                }),

            Action::make('update')
                ->label(fn (): string => __('Update to :version', ['version' => (string) $this->license?->latest_version]))
                ->icon(Heroicon::ArrowDownTray)
                ->visible(fn (): bool => $this->updateState === null && $this->latestIsNewer())
                ->requiresConfirmation()
                ->modalHeading(__('Update the system?'))
                ->modalDescription(__('The site goes into maintenance mode while it updates — only administrators can use it until it finishes, usually within a few minutes. Make sure you have a recent database backup first.'))
                ->modalSubmitActionLabel(__('Update now'))
                ->action(function (): void {
                    app(Updater::class)->start((string) $this->license?->latest_version);
                    $this->updateState = app(Updater::class)->state();
                }),

            Action::make('abandon')
                ->label(__('Cancel update and bring the site online'))
                ->color('danger')
                ->visible(fn (): bool => ($this->updateState['failed'] ?? false) === true)
                ->requiresConfirmation()
                ->modalDescription(__('The site comes back online as it is now. If the update failed after downloading the new version, parts of it may not work until the update is completed.'))
                ->action(function (): void {
                    app(Updater::class)->abandon();
                    $this->updateState = null;
                }),
        ];
    }

    private function latestIsNewer(): bool
    {
        $latest = $this->license?->latest_version;

        return $latest !== null && version_compare($latest, AppUpdate::currentVersion(), '>');
    }
}
