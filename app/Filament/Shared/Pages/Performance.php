<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Concerns\RendersFreshContent;
use App\Filament\Shared\Clusters\Settings;
use App\Http\Middleware\TrackPerformance;
use App\Models\PerformanceSample;
use App\Models\Setting;
use App\Support\AppUpdate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;

/**
 * How fast the system is, on this server with its real data: every request
 * is measured (App\Http\Middleware\TrackPerformance) — time, database
 * queries and repeated ones, memory, size — and shown here by screen, with
 * the slowest requests in full. "Check every screen" opens each screen in
 * turn, from this browser, as the signed-in user.
 */
class Performance extends Page implements HasTable
{
    use InteractsWithTable;
    use RendersFreshContent;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Bolt;

    protected static ?int $navigationSort = 33;

    protected static ?string $cluster = Settings::class;

    /** The period shown: 24h, 7d or 14d. */
    public string $period = '24h';

    /** A "Check every screen" run whose results are shown, instead of the period. */
    public ?string $check = null;

    /** The columns the screens can be ordered by (the table's sortable ones). */
    private const SCREEN_SORTS = ['requests', 'avg_ms', 'max_ms', 'avg_queries', 'avg_repeated'];

    public static function getNavigationLabel(): string
    {
        return __('Performance');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Maintenance');
    }

    public function getTitle(): string
    {
        return __('Performance');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:Performance') ?? false;
    }

    public function tracking(): bool
    {
        return (bool) Setting::get(TrackPerformance::SETTING, true);
    }

    /**
     * @return Builder<PerformanceSample>
     */
    private function samples(): Builder
    {
        return PerformanceSample::query()->when(
            $this->check,
            fn (Builder $q) => $q->where('check_id', $this->check),
            fn (Builder $q) => $q->where('created_at', '>=', $this->since()),
        );
    }

    /**
     * The requests the server answered. What the browser reports (method CLIENT) is
     * how long it WAITED — hours, when a connection stayed down — which says nothing
     * of how fast the server is, so it stays out of the summary and the slowest list
     * (it is still in the screens, and in the failed requests).
     *
     * @return Builder<PerformanceSample>
     */
    private function serverSamples(): Builder
    {
        return $this->samples()->where('method', '!=', 'CLIENT');
    }

    private function since(): Carbon
    {
        return match ($this->period) {
            '7d' => now()->subDays(7),
            '14d' => now()->subDays(14),
            default => now()->subDay(),
        };
    }

    /**
     * @return array{requests: int, avg: int, p95: int, slow: int, queries: float}
     */
    public function stats(): array
    {
        $count = $this->serverSamples()->count();

        // The time 95 in 100 requests were within (no percentile in SQL).
        $p95 = $count ? (int) $this->serverSamples()->orderBy('duration_ms')->skip((int) floor(0.95 * ($count - 1)))->value('duration_ms') : 0;

        return [
            'requests' => $count,
            'avg' => (int) round((float) $this->serverSamples()->avg('duration_ms')),
            'p95' => $p95,
            'slow' => $this->serverSamples()->where('duration_ms', '>=', TrackPerformance::SLOW_MS)->count(),
            'queries' => round((float) $this->serverSamples()->avg('queries'), 1),
        ];
    }

    /**
     * Each screen (or Livewire action): how often, how long, how many queries.
     *
     * @return Collection<int, object>
     */
    public function screens(int $limit = 60): Collection
    {
        // In the order the table is sorted by — slowest on average first.
        $order = in_array($this->getTableSortColumn(), self::SCREEN_SORTS, true) ? $this->getTableSortColumn() : 'avg_ms';
        $direction = $this->getTableSortColumn() !== null && $this->getTableSortDirection() === 'asc' ? 'asc' : 'desc';

        return $this->samples()
            ->toBase()
            ->selectRaw('name, count(*) as requests, avg(duration_ms) as avg_ms, max(duration_ms) as max_ms, avg(queries) as avg_queries, max(queries) as max_queries, avg(repeated) as avg_repeated, avg(memory_mb) as avg_mb, avg(response_kb) as avg_kb')
            ->groupBy('name')
            ->orderBy($order, $direction)
            ->limit($limit)
            ->get();
    }

    /**
     * The slowest requests, in full — with the query run most often.
     *
     * @return Collection<int, PerformanceSample>
     */
    public function slowest(int $limit = 25): Collection
    {
        return $this->serverSamples()
            ->with('user:id,name,display_name')
            ->orderByDesc('duration_ms')
            ->limit($limit)
            ->get();
    }

    public function updatedPeriod(): void
    {
        $this->check = null;
    }

    public function showPeriod(): void
    {
        $this->check = null;
    }

    /**
     * A new "Check every screen" run: its id, for the requests it makes.
     */
    #[Renderless]
    public function startCheck(): string
    {
        return (string) Str::uuid();
    }

    /**
     * The run done: its results shown.
     */
    public function showCheck(string $id): void
    {
        $this->check = Str::isUuid($id) ? $id : null;
    }

    /**
     * Every screen of this panel the user opens with a plain address (a
     * record's screens on its first record) — what the check opens.
     *
     * @return list<string>
     */
    #[Renderless]
    public function checkUrls(): array
    {
        $panel = Filament::getCurrentPanel();
        if (! $panel) {
            return [];
        }

        $prefix = 'filament.'.$panel->getId().'.';
        $urls = [];

        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $name = (string) $route->getName();

            if (! str_starts_with($name, $prefix) || ! in_array('GET', $route->methods(), true) || Str::contains($name, ['auth.', 'logout', 'performance'])) {
                continue;
            }

            $parameters = [];
            foreach ($route->parameterNames() as $parameter) {
                $key = $parameter === 'record' ? $this->firstRecordKey($name) : null;
                if ($key === null) {
                    continue 2;
                }
                $parameters['record'] = $key;
            }

            $urls[] = route($name, $parameters);
        }

        return array_values(array_unique($urls));
    }

    private function firstRecordKey(string $routeName): mixed
    {
        foreach (Filament::getCurrentPanel()?->getResources() ?? [] as $resource) {
            if (str_contains($routeName, '.resources.'.$resource::getSlug().'.')) {
                $model = $resource::getModel();

                return $model::query()->value((new $model)->getRouteKeyName());
            }
        }

        return null;
    }

    /**
     * Everything on this page, as text to send for a look: the server, the
     * figures, every screen, the slowest requests with their queries.
     */
    public function report(): string
    {
        $stats = $this->stats();
        $scope = $this->check
            ? 'Check of every screen ('.$this->check.')'
            : ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '14d' => 'Last 14 days'][$this->period] ?? $this->period;

        $lines = [
            '# Wakeel performance report',
            '',
            '- Generated: '.now()->toDateTimeString().' ('.config('app.timezone').')',
            '- Scope: '.$scope,
            '- App version: '.AppUpdate::currentVersion(),
            '- Server: PHP '.PHP_VERSION.', memory_limit '.ini_get('memory_limit').', max_execution_time '.ini_get('max_execution_time').', OPcache '.(function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false) ? 'on' : 'off'),
            '- Database: '.config('database.default').' '.(string) rescue(fn () => DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), '?', false),
            '- Drivers: cache '.config('cache.default').', session '.config('session.driver').', queue '.config('queue.default').', broadcasting '.config('broadcasting.default'),
            '- Config cached: '.(app()->configurationIsCached() ? 'yes' : 'no').', routes cached: '.(app()->routesAreCached() ? 'yes' : 'no').', debug: '.(config('app.debug') ? 'on' : 'off'),
            '',
            '## Summary',
            '',
            '- Requests: '.$stats['requests'],
            '- Average: '.$stats['avg'].' ms; 95% within: '.$stats['p95'].' ms',
            '- Slow (>= '.TrackPerformance::SLOW_MS.' ms): '.$stats['slow'],
            '- Queries per request: '.$stats['queries'],
            '',
            '## By screen',
            '',
            '| Screen or action | Requests | Avg ms | Max ms | Avg queries | Max queries | Avg repeated | Avg MB | Avg KB |',
            '|---|---:|---:|---:|---:|---:|---:|---:|---:|',
        ];

        foreach ($this->screens(500) as $row) {
            $lines[] = '| '.$row->name.' | '.$row->requests.' | '.round((float) $row->avg_ms).' | '.$row->max_ms.' | '.round((float) $row->avg_queries).' | '.$row->max_queries.' | '.round((float) $row->avg_repeated).' | '.round((float) $row->avg_mb, 1).' | '.round((float) $row->avg_kb).' |';
        }

        // Each screen's most repeated query, whether or not it was slow:
        // what to fix to stop a query running once per row.
        $lines = [...$lines, '', '## Most repeated query, by screen', ''];
        $repeats = $this->samples()
            ->whereNotNull('top_query')
            ->orderByDesc('repeated')
            ->get(['name', 'repeated', 'top_query'])
            ->unique('name')
            ->take(80);

        foreach ($repeats as $sample) {
            $lines[] = '- '.$sample->name.' ('.$sample->repeated.' repeated): `'.str_replace('`', "'", $sample->top_query).'`';
        }

        // What failed — answered with an error, or (status 0, seen by the
        // browser) not answered at all: the "Error while loading" notices.
        $lines = [...$lines, '', '## Failed requests', ''];
        $failed = $this->samples()
            ->where(fn (Builder $q) => $q->where('status', '>=', 400)->orWhere('method', 'CLIENT'))
            ->selectRaw('name, status, count(*) as times, max(created_at) as last_at, max(path) as page')
            ->groupBy('name', 'status')
            ->orderByDesc('times')
            ->limit(80)
            ->get();

        if ($failed->isEmpty()) {
            $lines[] = '- None.';
        }

        foreach ($failed as $row) {
            $lines[] = '- '.$row->times.'× status '.($row->status ?: '0 (no answer)').' — '.$row->name.' — last '.$row->last_at.' on '.$row->page;
        }

        $lines = [...$lines, '', '## Slowest requests', ''];

        foreach ($this->slowest(50) as $sample) {
            $lines[] = '- '.$sample->created_at->toDateTimeString().' — '.$sample->name.' — '.$sample->duration_ms.' ms, '.$sample->queries.' queries ('.$sample->repeated.' repeated), '.$sample->memory_mb.' MB, '.$sample->response_kb.' KB, status '.$sample->status.' — '.$sample->method.' '.$sample->path;
            if ($sample->top_query) {
                $lines[] = '  - most run: `'.str_replace('`', "'", $sample->top_query).'`';
            }
        }

        return implode("\n", $lines)."\n";
    }

    public function content(Schema $schema): Schema
    {
        $stats = $this->stats();

        return $schema->components([
            Flex::make([
                // The period, or a check's results.
                Flex::make([
                    Text::make(__('Results of the check of every screen'))
                        ->badge()
                        ->color('info')
                        ->visible(filled($this->check))
                        ->grow(false),
                    Actions::make([
                        Action::make('backToPeriod')
                            ->label(__('Back to all requests'))
                            ->link()
                            ->action(fn () => $this->showPeriod()),
                    ])
                        ->visible(filled($this->check))
                        ->grow(false),
                    Select::make('period')
                        ->hiddenLabel()
                        ->options([
                            '24h' => __('Last 24 hours'),
                            '7d' => __('Last 7 days'),
                            '14d' => __('Last 14 days'),
                        ])
                        ->native()
                        ->selectablePlaceholder(false)
                        ->live()
                        ->hidden(filled($this->check))
                        ->extraFieldWrapperAttributes(['style' => 'width: 12rem'])
                        ->grow(false),
                    Text::make(__('Measuring is stopped'))
                        ->badge()
                        ->color('warning')
                        ->hidden($this->tracking())
                        ->grow(false),
                ])->verticallyAlignCenter(),

                // Each screen opened in turn, from here, as you: on this
                // server, with its data. The requests go from the browser,
                // so Alpine drives the run; the page only hands out the
                // addresses and shows the results.
                Flex::make([
                    Text::make(new HtmlString(e(__('Opening screen')).' <span x-text="done"></span> / <span x-text="total"></span>'))
                        ->extraAttributes(['x-show' => 'running', 'x-cloak' => true])
                        ->grow(false),
                    Actions::make([
                        Action::make('checkEveryScreen')
                            ->label(__('Check every screen'))
                            ->icon(Heroicon::OutlinedPlayCircle)
                            ->alpineClickHandler('run()')
                            ->extraAttributes(['x-bind:disabled' => 'running']),
                    ])->grow(false),
                ])
                    ->verticallyAlignCenter()
                    ->extraAttributes(['x-data' => $this->checkRunAlpine()])
                    ->grow(false),
            ])->verticallyAlignCenter()->alignBetween(),

            Grid::make(['default' => 2, 'md' => 3, 'xl' => 5])->schema([
                $this->statCard('requests', __('Requests'), number_format($stats['requests'])),
                $this->statCard('avg', __('Average time'), number_format($stats['avg']).' ms', $this->tone($stats['avg'])),
                $this->statCard('p95', __('95% of requests within'), number_format($stats['p95']).' ms', $this->tone($stats['p95'])),
                $this->statCard('slow', __('Slow requests (1 s or more)'), number_format($stats['slow']), $stats['slow'] ? 'danger' : null),
                $this->statCard('queries', __('Database queries per request'), (string) $stats['queries']),
            ]),

            Section::make(__('By screen'))
                ->description(__('Repeated: the same query run again in one request — usually once per row of a list, the first thing to fix.'))
                ->schema([EmbeddedTable::make()]),

            Section::make(__('Slowest requests'))
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('slowest')
                        ->hiddenLabel()
                        ->state($this->slowest()->map(fn (PerformanceSample $sample): array => [
                            'when' => $sample->created_at->diffForHumans(),
                            'user' => $sample->user?->display_name ?: $sample->user?->name ?: '—',
                            'name' => $sample->name,
                            'top_query' => $sample->top_query,
                            'duration_ms' => $sample->duration_ms,
                            'queries' => $sample->queries,
                            'repeated' => $sample->repeated,
                            'status' => $sample->status,
                        ])->all())
                        ->placeholder(__('Nothing measured yet.'))
                        ->table([
                            TableColumn::make(__('When')),
                            TableColumn::make(__('User')),
                            TableColumn::make(__('Screen or action')),
                            TableColumn::make('ms')->alignEnd(),
                            TableColumn::make(__('Queries'))->alignEnd(),
                            TableColumn::make(__('Repeated'))->alignEnd(),
                            TableColumn::make(__('Status'))->alignEnd(),
                        ])
                        ->schema([
                            TextEntry::make('when'),
                            TextEntry::make('user'),
                            TextEntry::make('name')
                                ->extraAttributes(['dir' => 'ltr'])
                                // The query run most often, under the screen.
                                ->belowContent(fn (?string $state, TextEntry $component): ?Text => filled($query = data_get($component->getContainer()->getConstantState(), 'top_query'))
                                    ? Text::make($query)->fontFamily(FontFamily::Mono)->size(TextSize::ExtraSmall)->color('gray')->extraAttributes(['dir' => 'ltr', 'style' => 'word-break: break-all'])
                                    : null),
                            TextEntry::make('duration_ms')
                                ->numeric()
                                ->color(fn ($state): ?string => $this->tone((float) $state))
                                ->weight(fn ($state): ?FontWeight => $this->toneWeight((float) $state))
                                ->alignEnd(),
                            TextEntry::make('queries')->alignEnd(),
                            TextEntry::make('repeated')->alignEnd(),
                            TextEntry::make('status')->alignEnd(),
                        ]),
                ]),
        ]);
    }

    /**
     * Every screen, from the check run's addresses: each one fetched with the
     * run's id in a header, so the server files those requests under it.
     */
    private function checkRunAlpine(): string
    {
        // Single quotes only: this sits in an HTML attribute as it is.
        return '{
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
                    try { await fetch(url, { headers: { '.Js::from(TrackPerformance::CHECK_HEADER).': id }, credentials: \'same-origin\' }); } catch (e) {}
                    this.done++;
                }
                this.running = false;
                await $wire.showCheck(id);
            },
        }';
    }

    private function statCard(string $name, string $label, string $value, ?string $color = null): Section
    {
        return Section::make()
            ->compact()
            ->schema([
                TextEntry::make($name)
                    ->label($label)
                    ->state($value)
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold)
                    ->color($color),
            ]);
    }

    /**
     * A time's colour: red from a slow request, amber from half of one.
     */
    private function tone(float $ms): ?string
    {
        return match (true) {
            $ms >= TrackPerformance::SLOW_MS => 'danger',
            $ms >= TrackPerformance::SLOW_MS / 2 => 'warning',
            default => null,
        };
    }

    private function toneWeight(float $ms): ?FontWeight
    {
        return match ($this->tone($ms)) {
            'danger' => FontWeight::Bold,
            'warning' => FontWeight::SemiBold,
            default => null,
        };
    }

    /**
     * Each screen (or Livewire action) — see screens(); sorting it orders
     * the rows the server returns.
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->screens()
                ->mapWithKeys(fn (object $row): array => [$row->name => (array) $row])
                ->all())
            ->defaultSort('avg_ms', 'desc')
            ->paginated(false)
            ->emptyStateHeading(__('Nothing measured yet.'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Screen or action'))
                    ->extraAttributes(['dir' => 'ltr'])
                    ->wrap(),
                TextColumn::make('requests')
                    ->label(__('Requests'))
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('avg_ms')
                    ->label(__('Average ms'))
                    ->numeric()
                    ->color(fn ($state): ?string => $this->tone((float) $state))
                    ->weight(fn ($state): ?FontWeight => $this->toneWeight((float) $state))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('max_ms')
                    ->label(__('Slowest ms'))
                    ->numeric()
                    ->color(fn ($state): ?string => $this->tone((float) $state))
                    ->weight(fn ($state): ?FontWeight => $this->toneWeight((float) $state))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('avg_queries')
                    ->label(__('Queries'))
                    // The average, and the most one request ran.
                    ->formatStateUsing(fn ($state, array $record): HtmlString => new HtmlString(
                        e((string) round((float) $state)).' <small style="color: rgb(156 163 175);">/ '.e((string) $record['max_queries']).'</small>'
                    ))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('avg_repeated')
                    ->label(__('Repeated'))
                    ->formatStateUsing(fn ($state): string => (string) round((float) $state))
                    ->color(fn ($state): ?string => $state >= 10 ? 'danger' : null)
                    ->weight(fn ($state): ?FontWeight => $state >= 10 ? FontWeight::Bold : null)
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('avg_mb')
                    ->label(__('Memory MB'))
                    ->formatStateUsing(fn ($state): string => (string) round((float) $state, 1))
                    ->alignEnd(),
                TextColumn::make('avg_kb')
                    ->label(__('Size KB'))
                    ->numeric()
                    ->alignEnd(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Export report'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => response()->streamDownload(
                    function (): void {
                        echo $this->report();
                    },
                    'wakeel-performance-'.now()->format('Y-m-d-His').'.md',
                    ['Content-Type' => 'text/markdown; charset=UTF-8'],
                )),
            Action::make('tracking')
                ->label(fn () => $this->tracking() ? __('Stop measuring') : __('Start measuring'))
                ->icon(fn () => $this->tracking() ? 'heroicon-o-pause' : 'heroicon-o-play')
                ->color('gray')
                ->action(function (): void {
                    Setting::set(TrackPerformance::SETTING, ! $this->tracking(), 'system', 'boolean');
                    Setting::clearCache();
                }),
            Action::make('clear')
                ->label(__('Clear records'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    PerformanceSample::query()->delete();
                    $this->check = null;
                    Notification::make()->success()->title(__('Records cleared'))->send();
                }),
        ];
    }
}
