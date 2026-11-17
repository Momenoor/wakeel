<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something about a voice call, told at once to each of the two on their own channel (App.Models.User.{id}):
 * ringing, answered (its answer to be read), ended. Small on purpose — what the browsers offered and answered is
 * read from the server (ChatCallController::show), never sent through the channel, whose messages are kept short.
 */
class ChatCallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $userIds, public array $payload) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.call';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
