<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Messages someone sent were delivered or read: their chat redraws its
 * ticks. On each sender's personal channel, as ChatMessageSent.
 */
class ChatMessagesStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $userIds  the senders to tell
     */
    public function __construct(public array $userIds, public int $conversationId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.status';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
