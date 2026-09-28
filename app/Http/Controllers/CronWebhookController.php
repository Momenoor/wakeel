<?php

namespace App\Http\Controllers;

use App\Services\CronWebhookRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The scheduler, started by a web request instead of the host's cron — for
 * hosting that won't run cron every minute. An outside service (such as
 * cron-job.org) calls
 *
 *   GET /cron/run?token=<CRON_TOKEN>
 *
 * every minute. The answer goes back at once; the due tasks and the queue
 * worker then run after the response is sent.
 *
 * Off unless CRON_TOKEN in .env is set (at least 32 characters). A wrong or
 * missing token gets a 404, so the URL gives nothing away.
 */
class CronWebhookController extends Controller
{
    public function __invoke(Request $request, CronWebhookRunner $runner): JsonResponse
    {
        $token = (string) config('services.cron.token');

        abort_if(strlen($token) < 32 || ! hash_equals($token, (string) $request->query('token')), 404);

        if (! $runner->claim()) {
            return response()->json(['status' => 'busy'], 202);
        }

        defer(fn () => $runner->run());

        return response()->json(['status' => 'started', 'at' => now()->toDateTimeString()]);
    }
}
