<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Clusters\Settings;
use App\Http\Middleware\TrackPerformance;
use App\Models\PerformanceSample;
use App\Models\Setting;
use App\Support\AppUpdate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;

/**
 * How fast the system is, on this server with its real data: every request
 * is measured (App\Http\Middleware\TrackPerformance) — time, database
 * queries and repeated ones, memory, size — and shown here by screen, with
 * the slowest requests in full. "Check every screen" opens each screen in
 * turn, from this browser, as the signed-in user.
 */
class Performance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Bolt;

    protected static ?int $navigationSort = 33;

    protected static ?string $cluster = Settings::class;

    protected string $view = 'filament.shared.performance';

    /** The period shown: 24h, 7d or 14d. */
    public string $period = '24h';

    /** A "Check every screen" run whose results are shown, instead of the period. */
    public ?string $check = null;

    /** The screens' order: avg, max, queries, repeated, count. */
    public string $sort = 'avg';

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
        $count = $this->samples()->count();

        // The time 95 in 100 requests were within (no percentile in SQL).
        $p95 = $count ? (int) $this->samples()->orderBy('duration_ms')->skip((int) floor(0.95 * ($count - 1)))->value('duration_ms') : 0;

        return [
            'requests' => $count,
            'avg' => (int) round((float) $this->samples()->avg('duration_ms')),
            'p95' => $p95,
            'slow' => $this->samples()->where('duration_ms', '>=', TrackPerformance::SLOW_MS)->count(),
            'queries' => round((float) $this->samples()->avg('queries'), 1),
        ];
    }

    /**
     * Each screen (or Livewire action): how often, how long, how many queries.
     *
     * @return Collection<int, object>
     */
    public function screens(int $limit = 60): Collection
    {
        $order = match ($this->sort) {
            'max' => 'max_ms',
            'queries' => 'avg_queries',
            'repeated' => 'avg_repeated',
            'count' => 'requests',
            default => 'avg_ms',
        };

        return $this->samples()
            ->toBase()
            ->selectRaw('name, count(*) as requests, avg(duration_ms) as avg_ms, max(duration_ms) as max_ms, avg(queries) as avg_queries, max(queries) as max_queries, avg(repeated) as avg_repeated, avg(memory_mb) as avg_mb, avg(response_kb) as avg_kb')
            ->groupBy('name')
            ->orderByDesc($order)
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
        return $this->samples()
            ->with('user:id,name,display_name')
            ->orderByDesc('duration_ms')
            ->limit($limit)
            ->get();
    }

    public function sortBy(string $sort): void
    {
        $this->sort = $sort;
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

        $lines = [...$lines, '', '## Slowest requests', ''];

        foreach ($this->slowest(50) as $sample) {
            $lines[] = '- '.$sample->created_at->toDateTimeString().' — '.$sample->name.' — '.$sample->duration_ms.' ms, '.$sample->queries.' queries ('.$sample->repeated.' repeated), '.$sample->memory_mb.' MB, '.$sample->response_kb.' KB, status '.$sample->status.' — '.$sample->method.' '.$sample->path;
            if ($sample->top_query) {
                $lines[] = '  - most run: `'.str_replace('`', "'", $sample->top_query).'`';
            }
        }

        return implode("\n", $lines)."\n";
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
