<?php

namespace App\Jobs;

use App\Models\ChatCall;
use App\Services\Chat\ChatCalls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A call still ringing a while after it started: its notification sent again, so a locked phone — which sounds a
 * notification once — rings again (ChatCalls::start dispatches it twice). Nothing when it was answered or is over.
 */
class RingCallAgain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $callId) {}

    public function handle(ChatCalls $calls): void
    {
        $call = ChatCall::find($this->callId);

        if ($call !== null && $call->status === ChatCall::RINGING) {
            $calls->ringOnDevices($call);
        }
    }
}
