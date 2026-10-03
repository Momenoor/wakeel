<?php

namespace App\Livewire;

use App\Events\ChatMessagesStatusChanged;
use App\Filament\Mms\Pages\Chat;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatMessenger;
use App\Services\Notify\UserAlert;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Throwable;

/**
 * The real-time 1-on-1 chat between panel users, delivered over broadcasting
 * (Reverb locally, Pusher on shared hosting — see resources/js/echo.js).
 *
 * One component backs two presentations, switched purely at the view layer:
 * `mode="page"` is the full two-pane view embedded in the Chat nav page,
 * `mode="popup"` is the floating bottom-right bubble rendered on every panel
 * page via a render hook. Both share the same state and the same
 * conversation/message data — there's no separate "popup" data model.
 *
 * There is no permission gate: every user this app authenticates can reach
 * every other one here, the same way there wouldn't be one on an office
 * intercom. A conversation is created lazily the first time two users message
 * each other (see ChatConversation::betweenUsers()) and reused after that.
 */
class ChatWidget extends Component
{
    /** Session key holding the popup's open state and conversation. */
    private const POPUP_STATE = 'chat.popup';

    public string $mode = 'page';

    public bool $isOpen = false;

    public ?int $activeConversationId = null;

    public string $body = '';

    /** The message the next one answers. */
    public ?int $replyToId = null;

    /** A new group being made: its name and who's in it (besides me). */
    public bool $creatingGroup = false;

    public string $groupName = '';

    /** @var list<int> */
    public array $groupMembers = [];

    /** The group's members panel, open. */
    public bool $showMembers = false;

    /** @var list<int> people picked to add to the group */
    public array $addingMembers = [];

    public string $userSearch = '';

    /**
     * The newest message from someone else already seen — anything newer
     * pops the chat open.
     */
    public int $lastSeenMessageId = 0;

    /**
     * The message a notification points at: the list scrolls to it and
     * flashes it, instead of going to the newest.
     */
    public ?int $focusMessageId = null;

    /**
     * Opened from a notification: on its conversation, at its message.
     */
    public function mount(string $mode = 'page', ?int $conversation = null, ?int $message = null): void
    {
        abort_unless(Chat::canAccess(), 403);

        $this->mode = $mode;
        $this->lastSeenMessageId = (int) $this->incomingMessages()->max('id');
        $this->markIncomingDelivered();

        if ($conversation !== null) {
            $this->activeConversationId = $conversation;

            // Only one of this user's own conversations.
            if ($this->activeConversation === null) {
                $this->activeConversationId = null;
            } else {
                $this->focusMessageId = $message !== null && $this->activeConversation->messages()->whereKey($message)->exists() ? $message : null;
                $this->markActiveConversationRead();
            }
        }

        // The popup comes back as it was left — open, on the same
        // conversation — after a refresh or on the next page.
        if ($mode === 'popup') {
            $state = session(self::POPUP_STATE, []);
            $this->isOpen = (bool) ($state['open'] ?? false);
            $this->activeConversationId = $state['conversation'] ?? null;

            if ($this->activeConversationId !== null && $this->activeConversation === null) {
                $this->activeConversationId = null;
            }

            if ($this->isOpen) {
                $this->markActiveConversationRead();
            }
        }
    }

    /**
     * Remembers the popup's open state and conversation for the next page.
     */
    private function rememberState(): void
    {
        if ($this->mode === 'popup') {
            session([self::POPUP_STATE => ['open' => $this->isOpen, 'conversation' => $this->activeConversationId]]);
        }
    }

    /**
     * Messages from others in this user's conversations.
     *
     * @return Builder<ChatMessage>
     */
    private function incomingMessages(): Builder
    {
        return ChatMessage::query()
            ->whereIn('chat_conversation_id', Auth::user()->chatConversations()->select('chat_conversations.id'))
            ->where('user_id', '!=', Auth::id());
    }

    /**
     * A new message pops the floating chat open on its conversation — the
     * newest one's when several arrived — when it is closed or showing the
     * conversation list. A conversation already open stays open; the
     * unread badge shows the new message instead.
     */
    public function checkForNewMessages(): void
    {
        $this->markIncomingDelivered();

        $latest = $this->incomingMessages()
            ->where('id', '>', $this->lastSeenMessageId)
            ->latest('id')
            ->first();

        if ($latest === null) {
            return;
        }

        $this->lastSeenMessageId = (int) $latest->id;

        // A desktop notification too, when Wakeel is in a background tab —
        // the same tag as its push, so it never shows twice.
        $latest->loadMissing(['sender', 'conversation']);
        $this->dispatch(
            'wakeel-desktop-notification',
            id: 'chat-'.$latest->chat_conversation_id,
            // In a group: the group, then who wrote.
            title: ($latest->conversation?->is_group ? $latest->conversation->name.' — ' : '').(string) ($latest->sender?->display_name ?: $latest->sender?->name ?: __('Chat')),
            body: $latest->preview(),
            url: ChatMessenger::chatUrl($latest),
        );

        // A conversation on screen is being read as its messages arrive —
        // otherwise its own new message counted as unread elsewhere.
        if ($this->activeConversationId !== null && ($this->isOpen || $this->mode !== 'popup')) {
            $this->markActiveConversationRead();
        }

        if ($this->mode !== 'popup') {
            return;
        }

        if ($this->isOpen && $this->activeConversationId !== null) {
            return;
        }

        $this->isOpen = true;
        $this->activeConversationId = (int) $latest->chat_conversation_id;
        $this->markActiveConversationRead();
        $this->rememberState();
    }

    /**
     * @return Collection<int, ChatConversation>
     */
    public function getConversationsProperty(): Collection
    {
        return Auth::user()
            ->chatConversations()
            ->with('participants')
            ->latest('last_message_at')
            ->get();
    }

    /**
     * The other person in a 1-on-1 conversation — participants are always
     * loaded in full (self included) so isConversationUnread() can find this
     * user's own pivot row; this is where "self" gets filtered back out for
     * display.
     */
    public function otherParticipant(ChatConversation $conversation): ?User
    {
        return $conversation->participants->firstWhere('id', '!=', Auth::id());
    }

    /**
     * @return Collection<int, User>
     */
    public function getOtherUsersProperty(): Collection
    {
        return User::query()
            ->where('id', '!=', Auth::id())
            ->when($this->userSearch !== '', function ($q) {
                $q->where(function ($q) {
                    $q->where('name', 'like', "%{$this->userSearch}%")
                        ->orWhere('display_name', 'like', "%{$this->userSearch}%");
                });
            })
            ->orderBy('display_name')
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function getMessagesProperty(): Collection
    {
        // Scoped through activeConversation, not a raw where() on
        // activeConversationId — that property is client-settable (it's what
        // selectConversation()/wire:click write to), so resolving it through
        // $this->conversations (already scoped to the current user's own
        // relationship) is what stops a user requesting an id they don't
        // belong to from reading that conversation's messages.
        if (! $this->activeConversation) {
            return new Collection;
        }

        return ChatMessage::query()
            ->where('chat_conversation_id', $this->activeConversation->id)
            ->with(['sender', 'replyTo.sender'])
            ->orderBy('created_at')
            ->get();
    }

    public function getActiveConversationProperty(): ?ChatConversation
    {
        if (! $this->activeConversationId) {
            return null;
        }

        return $this->conversations->firstWhere('id', $this->activeConversationId);
    }

    public function getUnreadCountProperty(): int
    {
        return $this->conversations->filter(fn (ChatConversation $c) => $this->isConversationUnread($c))->count();
    }

    /**
     * Unread conversations other than the one on screen — the dot on the
     * back arrow.
     */
    public function getUnreadElsewhereCountProperty(): int
    {
        return $this->conversations
            ->filter(fn (ChatConversation $c) => $c->id !== $this->activeConversationId && $this->isConversationUnread($c))
            ->count();
    }

    /**
     * A single listener on the current user's own personal channel — the
     * same App.Models.User.{id} channel Laravel's model notifications already
     * broadcast on — rather than one listener per conversation. That channel
     * exists before any conversation does, so it's already subscribed from
     * this component's very first mount; a per-conversation channel would
     * only be added to this list for conversations that already existed the
     * last time this component rendered, which is what made a brand new
     * conversation invisible to its other participant until they refreshed.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if (! Auth::check()) {
            return [];
        }

        // The leading dot: the event is broadcast as plain
        // "chat.message.sent" (ChatMessageSent::broadcastAs()). Without it
        // Echo listens for "App\Events\chat.message.sent" instead, which
        // never arrives — messages then only showed at the next slow poll.
        return [
            'echo-private:App.Models.User.'.Auth::id().',.chat.message.sent' => 'onMessageReceived',
            // Messages of mine delivered or read: the redraw shows the ticks.
            'echo-private:App.Models.User.'.Auth::id().',.chat.status' => '$refresh',
        ];
    }

    public function onMessageReceived(): void
    {
        // The event payload isn't consumed directly — the message is read
        // back from the database, and the re-render after this shows it.
        $this->checkForNewMessages();
    }

    public function toggleOpen(): void
    {
        $this->isOpen = ! $this->isOpen;
        $this->rememberState();
    }

    public function backToList(): void
    {
        $this->activeConversationId = null;
        $this->showMembers = false;
        $this->replyToId = null;
        $this->rememberState();
    }

    public function selectConversation(int $conversationId): void
    {
        $this->activeConversationId = $conversationId;
        $this->focusMessageId = null;
        $this->showMembers = false;
        $this->replyToId = null;
        $this->markActiveConversationRead();
        $this->rememberState();
    }

    public function startConversationWith(int $userId): void
    {
        $other = User::findOrFail($userId);

        $conversation = ChatConversation::betweenUsers(Auth::user(), $other);

        $this->userSearch = '';
        $this->activeConversationId = $conversation->id;
        $this->markActiveConversationRead();
        $this->rememberState();
    }

    /**
     * The text comes as an argument from the thread's form, which has
     * already shown it and cleared the input before this request goes out
     * (see chat-thread.blade.php); $this->body is the fallback.
     */
    public function sendMessage(?string $text = null): void
    {
        $conversation = $this->activeConversation;

        if (! $conversation) {
            return;
        }

        // Files go with a message straight from the browser (see
        // ChatMessageController); this is a message typed.
        app(ChatMessenger::class)->send($conversation, Auth::user(), (string) ($text ?? $this->body), $this->replyToId);

        $this->body = '';
        $this->replyToId = null;
    }

    /**
     * Sent with files from the browser: the input clears, and the list
     * shows it.
     */
    public function sentWithFiles(): void
    {
        $this->replyToId = null;
        $this->markActiveConversationRead();
    }

    /**
     * Answer this message: it shows quoted above the input until sent.
     */
    public function replyTo(int $messageId): void
    {
        $this->replyToId = $this->activeConversation
            ? ChatMessage::query()->where('chat_conversation_id', $this->activeConversation->id)->whereKey($messageId)->value('id')
            : null;
    }

    /**
     * React to a message (❤️ 😂 👍 …) — the same one again takes it back.
     * The others' chats redraw with it.
     */
    public function react(int $messageId, string $emoji): void
    {
        $conversation = $this->activeConversation;
        $message = $conversation ? $conversation->messages()->whereKey($messageId)->first() : null;

        if (! $message || ! in_array($emoji, ChatMessage::REACTIONS, true)) {
            return;
        }

        $message->react((int) Auth::id(), $emoji);

        // Its writer hears of it — everywhere a notification shows (bell,
        // toast, desktop, phone), never by email — unless it is their own
        // message, or a reaction taken back.
        if ((int) $message->user_id !== (int) Auth::id() && ($message->reactions[Auth::id()] ?? null) === $emoji) {
            $me = Auth::user();
            $quote = $message->preview(120);
            UserAlert::send(
                $message->sender,
                ($conversation->is_group ? $conversation->name.' — ' : '').__(':name reacted :emoji', ['name' => (string) ($me->display_name ?: $me->name), 'emoji' => $emoji]),
                $quote !== '' ? __('To: “:message”', ['message' => $quote]) : null,
                ChatMessenger::chatUrl($message),
                'info',
                'heroicon-o-face-smile',
            );
        }

        $this->tellSenders($conversation->participants->pluck('id')->reject(fn ($id) => $id === Auth::id())->all(), $conversation->id);
    }

    /**
     * Who gave a reaction, for its tooltip: "You, Ahmed".
     *
     * @param  list<int>  $userIds
     */
    public function reactorNames(array $userIds): string
    {
        $people = $this->activeConversation?->participants->keyBy('id');

        return collect($userIds)
            ->map(fn (int $id) => $id === Auth::id() ? __('You') : ($people?->get($id)?->display_name ?: $people?->get($id)?->name ?: '—'))
            ->implode(', ');
    }

    /**
     * A message of mine no one else has read yet — or any message, for a
     * super admin — can be deleted.
     */
    public function canDelete(ChatMessage $message): bool
    {
        if (Auth::user()?->hasRole(Utils::getSuperAdminName())) {
            return true;
        }

        if ((int) $message->user_id !== (int) Auth::id()) {
            return false;
        }

        // Read by anyone else in the conversation (one of a group is enough).
        return ! (bool) $this->activeConversation?->participants
            ->reject(fn (User $u) => $u->id === Auth::id())
            ->contains(fn (User $u) => $u->pivot?->last_read_at?->gte($message->created_at));
    }

    /**
     * Gone for everyone — its files too; answers to it keep their text, the
     * quote goes. The others' chats redraw without it.
     */
    public function deleteMessage(int $messageId): void
    {
        $conversation = $this->activeConversation;
        $message = $conversation ? $conversation->messages()->whereKey($messageId)->first() : null;

        if (! $message || ! $this->canDelete($message)) {
            return;
        }

        foreach ($message->files() as $file) {
            Storage::disk(ChatMessage::DISK)->delete($file['path']);
        }

        $message->delete();

        // The conversation's latest message: what's left of it. Otherwise
        // the others' list showed it unread with nothing new in it.
        $conversation->update(['last_message_at' => $conversation->messages()->max('created_at')]);

        if ($this->replyToId === $messageId) {
            $this->replyToId = null;
        }

        unset($this->conversations);
        $this->tellSenders($conversation->participants->pluck('id')->reject(fn ($id) => $id === Auth::id())->all(), $conversation->id);
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
    }

    public function getReplyingToProperty(): ?ChatMessage
    {
        return $this->replyToId ? $this->messages->firstWhere('id', $this->replyToId) : null;
    }

    protected function markActiveConversationRead(): void
    {
        $conversation = $this->activeConversation;

        if (! $conversation) {
            return;
        }

        $readBefore = $conversation->participants->firstWhere('id', Auth::id())?->pivot?->last_read_at;
        $conversation->participants()->updateExistingPivot(Auth::id(), ['last_read_at' => now()]);

        // Their messages now read: their ticks turn.
        $senders = ChatMessage::query()
            ->where('chat_conversation_id', $conversation->id)
            ->where('user_id', '!=', Auth::id())
            ->when($readBefore, fn ($q) => $q->where('created_at', '>', $readBefore))
            ->distinct()
            ->pluck('user_id');
        $this->tellSenders($senders->all(), $conversation->id);

        // The list is cached for the request; reload it so the badge and
        // the back-arrow dot count this conversation as read.
        unset($this->conversations);
    }

    /**
     * Messages sent to this user arrived — their Wakeel is open: the
     * second tick, the senders told.
     */
    private function markIncomingDelivered(): void
    {
        $pending = $this->incomingMessages()->whereNull('delivered_at');
        $senders = (clone $pending)->select(['user_id', 'chat_conversation_id'])->distinct()->get();

        if ($senders->isEmpty()) {
            return;
        }

        $pending->update(['delivered_at' => now()]);

        foreach ($senders->groupBy('chat_conversation_id') as $conversationId => $rows) {
            $this->tellSenders($rows->pluck('user_id')->all(), (int) $conversationId);
        }
    }

    /**
     * @param  list<int|string>  $userIds
     */
    private function tellSenders(array $userIds, int $conversationId): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return;
        }

        defer(function () use ($userIds, $conversationId) {
            try {
                broadcast(new ChatMessagesStatusChanged($userIds, $conversationId));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * When the others in the conversation on screen last read it — all of
     * them, for a message of mine to count as read.
     */
    public function getReadByAllAtProperty(): ?Carbon
    {
        $others = $this->activeConversation?->participants->reject(fn (User $u) => $u->id === Auth::id());

        if (! $others || $others->isEmpty() || $others->contains(fn (User $u) => ! $u->pivot?->last_read_at)) {
            return null;
        }

        return $others->map(fn (User $u) => $u->pivot->last_read_at)->min();
    }

    /**
     * A message of mine: 'read', 'delivered' or 'sent'.
     */
    public function messageStatus(ChatMessage $message): string
    {
        $read = $this->readByAllAt;

        return match (true) {
            $read !== null && $read->gte($message->created_at) => 'read',
            $message->delivered_at !== null => 'delivered',
            default => 'sent',
        };
    }

    public function isConversationUnread(ChatConversation $conversation): bool
    {
        $pivot = $conversation->participants->firstWhere('id', Auth::id())?->pivot;

        if (! $pivot) {
            return false;
        }

        if (! $conversation->last_message_at) {
            return false;
        }

        return ! $pivot->last_read_at || $pivot->last_read_at->lt($conversation->last_message_at);
    }

    public function startGroup(): void
    {
        $this->creatingGroup = true;
        $this->groupName = '';
        $this->groupMembers = [];
        $this->userSearch = '';
        $this->resetErrorBag();
    }

    public function cancelGroup(): void
    {
        $this->creatingGroup = false;
        $this->userSearch = '';
        $this->resetErrorBag();
    }

    /**
     * The group made — me and at least two others — and opened.
     */
    public function createGroup(): void
    {
        $this->validate([
            'groupName' => ['required', 'string', 'max:100'],
            'groupMembers' => ['required', 'array', 'min:2'],
            'groupMembers.*' => ['integer', 'distinct', 'exists:users,id', 'not_in:'.Auth::id()],
        ], [], ['groupName' => __('Group name'), 'groupMembers' => __('Members')]);

        $group = ChatConversation::group(Auth::user(), $this->groupName, $this->groupMembers);

        $this->creatingGroup = false;
        $this->userSearch = '';
        $this->activeConversationId = $group->id;
        $this->showMembers = false;
        unset($this->conversations);
        $this->rememberState();
    }

    public function toggleMembers(): void
    {
        $this->showMembers = ! $this->showMembers;
        $this->addingMembers = [];
        $this->userSearch = '';
    }

    public function renameGroup(string $name): void
    {
        $group = $this->activeGroup();
        $name = trim($name);
        if ($group && $name !== '') {
            $group->update(['name' => mb_substr($name, 0, 100)]);
            unset($this->conversations);
        }
    }

    public function addMembers(): void
    {
        $group = $this->activeGroup();
        $ids = User::query()->whereKey(array_map('intval', $this->addingMembers))->pluck('id')->all();
        if ($group && $ids !== []) {
            $group->participants()->syncWithoutDetaching($ids);
            unset($this->conversations);
        }

        $this->addingMembers = [];
        $this->userSearch = '';
    }

    /**
     * Only whoever made the group removes others from it.
     */
    public function removeMember(int $userId): void
    {
        $group = $this->activeGroup();
        if ($group && (int) $group->created_by === (int) Auth::id() && $userId !== Auth::id()) {
            $group->participants()->detach($userId);
            unset($this->conversations);
        }
    }

    public function leaveGroup(): void
    {
        $group = $this->activeGroup();
        if (! $group) {
            return;
        }

        $group->participants()->detach(Auth::id());
        $this->activeConversationId = null;
        $this->showMembers = false;
        unset($this->conversations);
        $this->rememberState();
    }

    private function activeGroup(): ?ChatConversation
    {
        $conversation = $this->activeConversation;

        return $conversation?->is_group ? $conversation : null;
    }

    /**
     * People to pick: everyone else (not yet in the group being added to),
     * as searched.
     *
     * @return Collection<int, User>
     */
    public function getPickableUsersProperty(): Collection
    {
        $members = $this->showMembers && $this->activeConversation ? $this->activeConversation->participants->pluck('id')->all() : [Auth::id()];

        return User::query()
            ->whereNotIn('id', $members)
            ->when($this->userSearch !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->userSearch}%")->orWhere('display_name', 'like', "%{$this->userSearch}%")))
            ->orderBy('display_name')
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    /**
     * What a conversation is called: the group's name, or the other person's.
     */
    public function conversationTitle(ChatConversation $conversation): string
    {
        if ($conversation->is_group) {
            return (string) $conversation->name;
        }

        $other = $this->otherParticipant($conversation);

        return (string) ($other?->display_name ?: $other?->name);
    }

    public function groupAvatarUrl(ChatConversation $conversation): string
    {
        return 'https://ui-avatars.com/api/?name='.urlencode((string) $conversation->name).'&size=128&background=7C3AED&color=FFFFFF';
    }

    /**
     * A stable, correctly-sized avatar URL for any user — the initials
     * fallback is asked for a fixed high-resolution image (2x the largest
     * size this UI ever displays one at) so it stays crisp instead of being
     * upscaled and blurry, and a real uploaded avatar is masked to a circle
     * by the same `object-cover` class every avatar in this widget uses.
     */
    public function avatarUrl(User $user): string
    {
        return $user->getFilamentAvatarUrl()
            ?? 'https://ui-avatars.com/api/?name='.urlencode($user->display_name ?: $user->name).'&size=128&background=2563EB&color=FFFFFF';
    }

    /**
     * Who counts as online by last_seen_at — the fallback the status dots
     * read (through $wire) when there's no live presence channel. Kept in
     * one property rather than in each dot's HTML, so a re-render never
     * fights Alpine over the dots.
     *
     * @var list<int>
     */
    public array $onlineUserIds = [];

    public function render(): View
    {
        $this->onlineUserIds = User::query()
            ->where('last_seen_at', '>', now()->subSeconds(User::ONLINE_WITHIN_SECONDS))
            ->pluck('id')
            ->all();

        return view('livewire.chat-widget');
    }
}
