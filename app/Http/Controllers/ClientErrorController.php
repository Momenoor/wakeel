<?php

namespace App\Http\Controllers;

use App\Models\PerformanceSample;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A background request the browser saw fail — what Filament reports as
 * "Error while loading the page": an error answer, or none at all (the
 * connection dropped, the server gone) which never reaches the server's
 * own records. Kept with the Performance page's samples (status 0: no
 * answer) and in the log, so the report says what failed, where and when.
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

        Log::warning('A background request failed in the browser', ['status' => $data['status'], 'request' => $data['name'], 'page' => $page, 'user' => $request->user()?->getKey()]);

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
}
