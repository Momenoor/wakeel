<?php

namespace App\Http\Controllers;

use App\Services\MMS\Letters\MinutesSignedCopies;
use App\Services\WhatsAppCloud;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

/**
 * Meta's WhatsApp webhook (set in the Meta app as the callback URL, with
 * the verify token from WhatsApp settings; subscribed to "messages").
 *
 * GET  — Meta checking the URL: the challenge back, for the right token.
 * POST — messages people sent in, signed with the app secret: a file sent
 *        back on minutes sent for signature is taken in — after the answer
 *        has gone, as Meta wants one within seconds.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        // PHP reads "hub.mode" as "hub_mode".
        abort_unless($request->query('hub_mode') === 'subscribe'
            && hash_equals(WhatsAppCloud::verifyToken(), (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, MinutesSignedCopies $copies): Response
    {
        abort_unless(WhatsAppCloud::validSignature($request->getContent(), $request->header('X-Hub-Signature-256')), 403);

        $messages = collect($request->input('entry', []))
            ->flatMap(fn ($entry) => (array) ($entry['changes'] ?? []))
            ->flatMap(fn ($change) => (array) ($change['value']['messages'] ?? []))
            ->filter(fn ($message) => is_array($message))
            ->values()
            ->all();

        if ($messages !== []) {
            defer(function () use ($messages, $copies) {
                foreach ($messages as $message) {
                    try {
                        $copies->receive($message);
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp message not taken in', ['id' => $message['id'] ?? null, 'error' => $e->getMessage()]);
                    }
                }
            });
        }

        return response('ok');
    }
}
