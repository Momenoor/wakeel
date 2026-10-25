<?php

namespace App\Http\Controllers;

use App\Services\Backup\DatabaseRestore;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * The page a database restore runs from, to its end: reached with the restore's own key — no login, no session,
 * no cookie, for the tables they live in are dropped and made again under it — and let through the maintenance
 * mode it puts the site in (bootstrap/app.php). Its page asks for each step until the restore is done.
 */
class SystemRestoreController extends Controller
{
    public function __construct(private readonly DatabaseRestore $restore) {}

    public function show(string $key): View
    {
        abort_if($this->restore->stateFor($key) === null, 404);

        return view('system-restore', ['key' => $key]);
    }

    public function step(string $key): JsonResponse
    {
        abort_if($this->restore->stateFor($key) === null, 404);

        // Another step still running (a reload, two tabs): told so, and asked again.
        $state = $this->restore->step($key);

        return response()->json($state === null ? ['busy' => true] : self::shown($state));
    }

    public function restart(string $key): JsonResponse
    {
        abort_if($this->restore->stateFor($key) === null, 404);
        $this->restore->restart($key);

        return response()->json(['ok' => true]);
    }

    /**
     * What the page shows of a restore: where it is, how far, and what stopped it.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function shown(array $state): array
    {
        return [
            'phase' => $state['phase'],
            'percent' => $state['size'] > 0 ? (int) min(100, floor($state['offset'] * 100 / $state['size'])) : 0,
            'statements' => $state['statements'],
            'error' => $state['error'],
            'done' => $state['phase'] === 'done',
        ];
    }
}
