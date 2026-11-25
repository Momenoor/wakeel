<?php

namespace App\Http\Controllers;

use App\Models\PerformanceSample;
use App\Support\ErrorTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A background request the browser saw fail — what Filament reports as
 * "Error while loading the page": an error answer, or none at all (the
 * connection dropped, the server gone) which never reaches the server's
 * own records. Kept with the Performance page's samples (status 0: no
 * answer), in the log, and on Settings → Error tracking as a warning (the
 * same request failing on the same page again counted, not a new row), so
 * the report says what failed, where and when.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'status' => ['required', 'integer', 'min:0', 'max:599'],
            'name' => ['required', 'string', 'max:500'],
            'page' => ['nullable', 'string', 'max:500'],
            'ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $name = Str::limit('browser: '.$data['name'], 190, '');
        $page = '/'.ltrim((string) (parse_url((string) ($data['page'] ?? ''), PHP_URL_PATH) ?? ''), '/');

        // No answer at all (status 0: the phone locked, the line dropped, Pusher lost) is the user's connection, not
        // a fault of the server: kept with the Performance page's samples, not a warning in the log.
        if ((int) $data['status'] !== 0) {
            Log::warning('A background request failed in the browser', ['status' => $data['status'], 'request' => $data['name'], 'page' => $page, 'user' => $request->user()?->getKey()]);
        }

        ErrorTracker::warning(
            'Browser request failed',
            'A background request failed in the browser ('.self::statusText((int) $data['status']).'): '.$data['name'].' on '.$page,
            ['status' => (int) $data['status'], 'request' => $data['name'], 'page' => $page, 'ms' => $data['ms'] ?? null],
            groupBy: $data['status'].'|'.$data['name'].'|'.ErrorTracker::normalise($page),
            status: (int) $data['status'] ?: null,
            request: $request,
        );

        PerformanceSample::create([
            'user_id' => $request->user()?->getKey(),
            'method' => 'CLIENT',
            'path' => Str::limit($page, 250, ''),
            'name' => $name,
            'status' => (int) $data['status'],
            'duration_ms' => (int) ($data['ms'] ?? 0),
            'queries' => 0,
            'repeated' => 0,
            'memory_mb' => 0,
            'response_kb' => 0,
        ]);

        return response()->noContent();
    }

    /** "500", "403", or "no answer" for 0: the connection dropped, the server gone. */
    private static function statusText(int $status): string
    {
        return $status === 0 ? 'no answer' : (string) $status;
    }
}
