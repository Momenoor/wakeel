<?php

namespace App\Services\Chat;

use App\Events\ChatCallSignal;
use App\Jobs\RingCallAgain;
use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\WebPushSender;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Voice calls in the chat, between the two people of a one-to-one conversation. Their browsers talk to each
 * other (WebRTC); this keeps the call and passes it between them:
 *
 * 1. The caller's browser makes its offer and starts the call: it rings for the other (ChatCallSignal "ring").
 * 2. The one called answers: their browser reads the offer, makes its answer and sends it; the caller is told
 *    ("answered") and reads it. The two browsers are then connected, directly or through the office's TURN
 *    server (iceServers()).
 * 3. Either hangs up — or the one called declines, or nobody answers in RING_SECONDS: the call ends, both are
 *    told ("ended"), and a line in the conversation says how it went ("Voice call · 4:12", "Missed voice call").
 *
 * A call nobody ended — a browser closed while ringing, or both gone mid-call without hanging up — is closed by
 * closeStale(), so no one stays "busy".
 */
class ChatCalls
{
    public function __construct(private ChatMessenger $messenger) {}

    /**
     * The servers a browser uses to find the other: a public STUN server, and the office's TURN server (coturn)
     * when one is set up, with a password made for this user that lasts a day (coturn's use-auth-secret).
     *
     * @return list<array<string, mixed>>
     */
    public static function iceServers(User $user): array
    {
        $servers = [];

        $stun = array_values(array_filter(array_map('trim', explode(',', (string) config('services.webrtc.stun')))));
        if ($stun !== []) {
            $servers[] = ['urls' => $stun];
        }

        $turn = array_values(array_filter(array_map('trim', explode(',', (string) config('services.webrtc.turn_urls')))));
        $secret = (string) config('services.webrtc.turn_secret');
        if ($turn !== [] && $secret !== '') {
            $username = (now()->getTimestamp() + 86400).':'.$user->getKey();
            $servers[] = [
                'urls' => $turn,
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            ];
        }

        return $servers;
    }

    /**
     * A call started: rings for the other one of the conversation.
     *
     * @return ChatCall|string the call, or why it could not be made ("busy", "not_one_to_one")
     */
    public function start(ChatConversation $conversation, User $caller, string $offer): ChatCall|string
    {
        if ($conversation->is_group) {
            return 'not_one_to_one';
        }

        $callee = $conversation->participants->firstWhere('id', '!=', $caller->getKey());
        if ($callee === null) {
            return 'not_one_to_one';
        }

        $this->closeStale();

        // On another call already, either of them.
        $busy = ChatCall::query()
            ->whereIn('status', [ChatCall::RINGING, ChatCall::ANSWERED])
            ->where(fn ($q) => $q->whereIn('caller_id', [$caller->getKey(), $callee->getKey()])
                ->orWhereIn('callee_id', [$caller->getKey(), $callee->getKey()]))
            ->exists();
        if ($busy) {
            return 'busy';
        }

        $call = ChatCall::create([
            'chat_conversation_id' => $conversation->getKey(),
            'caller_id' => $caller->getKey(),
            'callee_id' => $callee->getKey(),
            'status' => ChatCall::RINGING,
            'offer' => $offer,
        ]);

        $this->signal([(int) $callee->getKey(), (int) $caller->getKey()], [
            'event' => 'ring',
            'call_id' => $call->getKey(),
            'conversation_id' => (int) $conversation->getKey(),
            'caller_id' => (int) $caller->getKey(),
            'name' => (string) ($caller->display_name ?: $caller->name),
            'avatar' => $caller->getFilamentAvatarUrl(),
        ]);

        // Also on their phones and computers with no Wakeel page open, or locked — after the answer has gone
        // back; and again twice while it still rings, a phone sounding a notification once.
        $call->setRelation('caller', $caller);
        defer(fn () => $this->ringOnDevices($call));
        RingCallAgain::dispatch((int) $call->getKey())->delay(now()->addSeconds(15));
        RingCallAgain::dispatch((int) $call->getKey())->delay(now()->addSeconds(30));

        return $call;
    }

    /** The call's ringing notification taken off the phones and computers of the one called, after the answer. */
    private function stopRingingOnDevices(ChatCall $call): void
    {
        $calleeId = (int) $call->callee_id;
        $conversationId = (int) $call->chat_conversation_id;

        if (! PushSubscription::where('user_id', $calleeId)->exists()) {
            return;
        }

        defer(function () use ($calleeId, $conversationId) {
            try {
                app(WebPushSender::class)->sendToUser($calleeId, WebPushSender::closing($conversationId));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** The call's notification pushed to the one called (WebPushSender::callPayload). */
    public function ringOnDevices(ChatCall $call): void
    {
        $call->loadMissing('caller');
        if ($call->caller === null || ! PushSubscription::where('user_id', $call->callee_id)->exists()) {
            return;
        }

        try {
            app(WebPushSender::class)->sendToUser((int) $call->callee_id, WebPushSender::callPayload(
                (int) $call->chat_conversation_id,
                (string) ($call->caller->display_name ?: $call->caller->name),
                $call->caller->getFilamentAvatarUrl(),
                ChatMessenger::chatUrl().(str_contains(ChatMessenger::chatUrl(), '?') ? '&' : '?').'conversation='.$call->chat_conversation_id,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The one called answers with their browser's answer: the caller reads it and the call is on. */
    public function answer(ChatCall $call, User $user, string $answer): bool
    {
        $answered = ChatCall::query()
            ->whereKey($call->getKey())
            ->where('callee_id', $user->getKey())
            ->where('status', ChatCall::RINGING)
            ->update(['status' => ChatCall::ANSWERED, 'answer' => $answer, 'answered_at' => now(), 'updated_at' => now()]);

        if (! $answered) {
            return false;
        }

        // The caller reads the answer; the callee's other tabs stop ringing, and their other phones and computers.
        $this->signal([(int) $call->caller_id, (int) $call->callee_id], ['event' => 'answered', 'call_id' => $call->getKey()]);
        $this->stopRingingOnDevices($call);

        return true;
    }

    /**
     * Hung up, declined, cancelled, not answered in time — or the browsers could not reach each other.
     *
     * @param  string  $reason  hangup, decline, cancel, timeout or failed
     */
    public function end(ChatCall $call, User $user, string $reason): void
    {
        $call->refresh();
        if (! $call->isLive()) {
            return;
        }

        $status = match (true) {
            $reason === 'failed' => ChatCall::FAILED,
            $call->status === ChatCall::ANSWERED => ChatCall::ENDED,
            $reason === 'decline' && (int) $user->getKey() === (int) $call->callee_id => ChatCall::DECLINED,
            default => ChatCall::MISSED,
        };

        $this->finish($call, $status);
    }

    /** An answered call goes on: both browsers say so every so often, or closeStale() ends it. */
    public function ping(ChatCall $call): void
    {
        if ($call->status === ChatCall::ANSWERED) {
            $call->touch();
        }
    }

    /**
     * Calls nobody ended: ringing past its time (the caller's browser gone) is missed; answered with no word
     * from either browser for SILENT_SECONDS is over, when that was last heard.
     */
    public function closeStale(): void
    {
        $stale = ChatCall::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', ChatCall::RINGING)->where('created_at', '<', now()->subSeconds(ChatCall::RING_SECONDS + 15)))
                ->orWhere(fn ($q) => $q->where('status', ChatCall::ANSWERED)->where('updated_at', '<', now()->subSeconds(ChatCall::SILENT_SECONDS))))
            ->get();

        foreach ($stale as $call) {
            $this->finish($call, $call->status === ChatCall::ANSWERED ? ChatCall::ENDED : ChatCall::MISSED, $call->status === ChatCall::ANSWERED ? $call->updated_at : null);
        }
    }

    /**
     * The call over: kept so, both told, and its line written in the conversation. Once — a second end of the
     * same call (both hanging up together) changes nothing.
     */
    private function finish(ChatCall $call, string $status, $endedAt = null): void
    {
        $changed = ChatCall::query()
            ->whereKey($call->getKey())
            ->whereIn('status', [ChatCall::RINGING, ChatCall::ANSWERED])
            ->update(['status' => $status, 'ended_at' => $endedAt ?? now(), 'updated_at' => now()]);

        if (! $changed) {
            return;
        }

        $call->refresh()->load(['conversation.participants', 'caller']);

        // Its ringing notification taken off the phones of the one called — before a missed call's own takes its
        // place (both after the answer has gone back, in this order).
        $this->stopRingingOnDevices($call);

        try {
            DB::transaction(function () use ($call, $status) {
                $message = ChatMessage::create([
                    'chat_conversation_id' => $call->chat_conversation_id,
                    'user_id' => $call->caller_id,
                    'chat_call_id' => $call->getKey(),
                    'body' => '',
                ]);
                $message->setRelation('chatCall', $call);

                // Only a call missed is news for the one called: in their bell and pushed, as a message is.
                $this->messenger->tell($message, $call->conversation, $call->caller, alert: $status === ChatCall::MISSED);

                // One they took part in, or declined themselves, is no unread message for them.
                if ($status !== ChatCall::MISSED) {
                    $call->conversation->participants()->updateExistingPivot($call->callee_id, ['last_read_at' => $message->created_at]);
                }
            });
        } catch (Throwable $e) {
            report($e);
        }

        $this->signal([(int) $call->caller_id, (int) $call->callee_id], ['event' => 'ended', 'call_id' => $call->getKey(), 'status' => $status]);
    }

    /**
     * Told at once — a call rings now or not at all; the broadcaster down is no failed request.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    private function signal(array $userIds, array $payload): void
    {
        try {
            broadcast(new ChatCallSignal(array_values(array_unique($userIds)), $payload));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
