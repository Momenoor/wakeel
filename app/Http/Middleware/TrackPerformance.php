<?php

namespace App\Http\Middleware;

use App\Models\PerformanceSample;
use App\Models\Setting;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What each request cost — time, database queries (and how many were the
 * same query again, a query per row of a list), memory and the size sent
 * back — kept for the Performance page. Written after the response has
 * gone, so measuring adds nothing to the wait.
 *
 * A singleton (AppServiceProvider): terminate() reads what handle() counted.
 */
class TrackPerformance
{
    /** The setting that turns it off. */
    public const SETTING = 'performance_tracking';

    /** A request this slow (ms) keeps its most repeated query. */
    public const SLOW_MS = 1000;

    /** The header the "Check every screen" run marks its requests with. */
    public const CHECK_HEADER = 'X-Wakeel-Check';

    /**
     * Livewire's routine background calls: kept only when slow — one every
     * few seconds per open tab would bury everything else.
     */
    private const ROUTINE = ['checkForNewMessages', 'checkNotifications', '__lazyLoad'];

    private ?float $start = null;

    /** @var array<string, int> */
    private array $queries = [];

    private bool $listening = false;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled() || $this->ignored($request)) {
            $this->start = null;

            return $next($request);
        }

        $this->start = microtime(true);
        $this->queries = [];

        if (! $this->listening) {
            $this->listening = true;
            DB::listen(function (QueryExecuted $query): void {
                if ($this->start !== null && count($this->queries) < 2000) {
                    $sql = preg_replace('/\s+/', ' ', $query->sql);
                    $this->queries[$sql] = ($this->queries[$sql] ?? 0) + 1;
                }
            });
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->start === null) {
            return;
        }

        $duration = (int) round((microtime(true) - $this->start) * 1000);
        $this->start = null;

        $name = $this->name($request);
        $routine = Str::contains($name, self::ROUTINE);

        if ($routine && $duration < self::SLOW_MS) {
            return;
        }

        arsort($this->queries);
        $total = array_sum($this->queries);
        $repeated = $total - count($this->queries);
        $top = $this->queries ? array_key_first($this->queries) : null;
        $topCount = $top !== null ? $this->queries[$top] : 0;

        try {
            PerformanceSample::create([
                'user_id' => $request->user()?->getKey(),
                'method' => $request->method(),
                'path' => Str::limit('/'.ltrim($request->path(), '/'), 250, ''),
                'name' => Str::limit($name, 190, ''),
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
                'queries' => $total,
                'repeated' => $repeated,
                // The query run most often, when it was run again and again
                // or the request was slow: what to look at first.
                'top_query' => $top !== null && ($topCount >= 5 || $duration >= self::SLOW_MS) ? Str::limit($topCount.'× '.$top, 2000) : null,
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'response_kb' => (int) round(strlen((string) $response->getContent()) / 1024),
                'check_id' => Str::limit((string) $request->header(self::CHECK_HEADER), 36, '') ?: null,
            ]);
        } catch (Throwable $e) {
            // Never in the way: no table yet (installing), the database gone …
        }
    }

    private function enabled(): bool
    {
        try {
            return (bool) Setting::get(self::SETTING, true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Files served through Laravel — scripts, fonts, attachments — say
     * nothing about the screens.
     */
    private function ignored(Request $request): bool
    {
        return $request->is('livewire*/livewire*.js', 'livewire*/*.js.map', 'build/*', 'fonts/*', 'up', '*/letter-fonts/*', 'chat/attachments/*', 'chat-attachments/*');
    }

    /**
     * The screen ("matters.index"), or for Livewire's own requests what was
     * done in which component ("chat-widget@selectConversation").
     */
    private function name(Request $request): string
    {
        $components = $request->input('components');

        if ($request->isMethod('post') && is_array($components)) {
            $parts = [];
            foreach ($components as $component) {
                $memo = json_decode((string) ($component['snapshot'] ?? ''), true)['memo'] ?? [];
                $calls = array_column((array) ($component['calls'] ?? []), 'method');
                $component = class_basename(str_replace('.', '\\', (string) ($memo['name'] ?? 'livewire')));
                $parts[] = $component.($calls ? '@'.implode(',', array_unique($calls)) : '@update');
            }

            return 'livewire: '.implode(' + ', array_unique($parts));
        }

        $route = (string) $request->route()?->getName();

        return $route !== '' ? (string) preg_replace('/^filament\.(mms|pms)\./', '$1: ', $route) : '/'.ltrim($request->path(), '/');
    }
}
