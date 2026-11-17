<?php

namespace App\Http\Controllers;

use App\Filament\Mms\Pages\Chat;
use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Services\Chat\ChatCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A voice call's steps from the browser (filament.partials.chat-call): the servers to find each other through,
 * start, read the other's offer or answer, answer, keep going, end. Only the two in the call reach it.
 */
class ChatCallController extends Controller
{
    /** Longest offer or answer taken — a browser's is a few kilobytes. */
    private const MAX_SDP = 20000;

    public function __construct(private ChatCalls $calls) {}

    public function ice(Request $request): JsonResponse
    {
        abort_unless(Chat::canAccess(), 403);

        return response()->json(['iceServers' => ChatCalls::iceServers($request->user())]);
    }

    public function store(Request $request, ChatConversation $conversation): JsonResponse
    {
        abort_unless(Chat::canAccess(), 403);
        abort_unless($conversation->participants()->whereKey($request->user()->getKey())->exists(), 403);

        $data = $request->validate(['offer' => ['required', 'string', 'max:'.self::MAX_SDP]]);

        $call = $this->calls->start($conversation->load('participants'), $request->user(), $data['offer']);

        if (is_string($call)) {
            return response()->json([
                'error' => $call,
                'message' => $call === 'busy' ? __('On another call. Try again later.') : __('Calls are between two people only.'),
            ], $call === 'busy' ? 409 : 422);
        }

        return response()->json(['id' => $call->getKey(), 'ring_seconds' => ChatCall::RING_SECONDS]);
    }

    /** The call as it is — with the offer for the one called, the answer for the caller. */
    public function show(Request $request, ChatCall $call): JsonResponse
    {
        $me = (int) $request->user()->getKey();
        abort_unless($call->involves($me), 403);

        $caller = $call->caller;

        return response()->json([
            'id' => $call->getKey(),
            'status' => $call->status,
            'conversation_id' => (int) $call->chat_conversation_id,
            'caller_id' => (int) $call->caller_id,
            'name' => (string) ($caller?->display_name ?: $caller?->name),
            'avatar' => $caller?->getFilamentAvatarUrl(),
            'offer' => $me === (int) $call->callee_id ? $call->offer : null,
            'answer' => $me === (int) $call->caller_id ? $call->answer : null,
        ]);
    }

    /** The live call of mine, ringing for me — for a page opened while it rings. */
    public function current(Request $request): JsonResponse
    {
        $this->calls->closeStale();

        $call = ChatCall::query()
            ->where('callee_id', $request->user()->getKey())
            ->where('status', ChatCall::RINGING)
            ->latest('id')
            ->first();

        return response()->json(['call_id' => $call?->getKey()]);
    }

    public function answer(Request $request, ChatCall $call): JsonResponse
    {
        abort_unless((int) $call->callee_id === (int) $request->user()->getKey(), 403);

        $data = $request->validate(['answer' => ['required', 'string', 'max:'.self::MAX_SDP]]);

        return $this->calls->answer($call, $request->user(), $data['answer'])
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'over', 'message' => __('The call has ended.')], 409);
    }

    public function ping(Request $request, ChatCall $call): Response
    {
        abort_unless($call->involves((int) $request->user()->getKey()), 403);

        $this->calls->ping($call);

        return response()->noContent();
    }

    public function end(Request $request, ChatCall $call): Response
    {
        abort_unless($call->involves((int) $request->user()->getKey()), 403);

        $data = $request->validate(['reason' => ['required', 'in:hangup,decline,cancel,timeout,failed']]);

        $this->calls->end($call, $request->user(), $data['reason']);

        return response()->noContent();
    }
}
