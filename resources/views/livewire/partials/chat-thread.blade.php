@if (! $isPopup)
    @php($other = $this->activeConversation ? $this->otherParticipant($this->activeConversation) : null)
    <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/10">
        @if ($other)
            @include('livewire.partials.chat-avatar', ['user' => $other, 'size' => 36])
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="truncate font-semibold text-gray-950 dark:text-white">{{ $other->display_name ?: $other->name }}</span>
                {{-- Live like the avatar dot — see chat-avatar.blade.php --}}
                <span
                    x-data="{ get on() { const ids = $store.chatOnline?.ids; return (ids ?? $wire.onlineUserIds).includes({{ $other->id }}); } }"
                    class="text-xs"
                    :class="on ? 'text-green-600 dark:text-green-400' : 'text-gray-400'"
                    x-text="on ? @js(__('Online')) : @js(__('Offline'))"
                ></span>
            </span>
        @else
            <span class="font-semibold text-gray-500 dark:text-gray-400">{{ __('Chat') }}</span>
        @endif
    </div>
@endif

@if ($this->activeConversation)
    {{--
        Keeps the newest message in view: when the list first shows (the
        popup opening resizes it from display:none), when messages arrive
        or are sent, as long as the reader was already at the bottom —
        scrolling up to read history isn't yanked back down. Keyed per
        conversation so switching starts again at its latest message.
    --}}
    <div
        x-ref="messageList"
        data-chat-messages
        wire:key="chat-messages-{{ $this->activeConversation->id }}"
        x-data="{
            stick: true,
            toBottom() {
                this.$el.scrollTop = this.$el.scrollHeight;
            },
            follow() {
                if (this.stick) { this.toBottom(); }
            },
        }"
        x-init="
            toBottom();
            new MutationObserver(() => follow()).observe($el, { childList: true, subtree: true, characterData: true });
            new ResizeObserver(() => follow()).observe($el);
        "
        x-on:scroll="stick = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 80"
        x-on:message-sent.window="stick = true; toBottom()"
        class="flex-1 space-y-3 overflow-y-auto p-4"
    >
        @php($lastDay = null)
        @foreach ($this->messages as $message)
            @php($isMine = $message->user_id === auth()->id())
            @php($day = $message->created_at->copy()->startOfDay())
            @if (! $lastDay || ! $day->equalTo($lastDay))
                @php($lastDay = $day)
                {{-- One label per day, WhatsApp-style --}}
                <div class="flex justify-center" wire:key="chat-day-{{ $day->format('Y-m-d') }}">
                    <span class="rounded-full bg-gray-100 px-3 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400" style="padding-top: 2px; padding-bottom: 2px;">
                        @if ($day->isToday())
                            {{ __('Today') }}
                        @elseif ($day->isYesterday())
                            {{ __('Yesterday') }}
                        @else
                            {{ $day->translatedFormat($day->isCurrentYear() ? 'l, j F' : 'j F Y') }}
                        @endif
                    </span>
                </div>
            @endif
            <div @class(['flex', 'justify-end' => $isMine])>
                <div @class([
                    'max-w-[80%] rounded-2xl px-4 py-2 text-sm shadow-sm',
                    'rounded-br-md bg-gradient-to-br from-primary-600 to-primary-500 text-white' => $isMine,
                    'rounded-bl-md bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white' => ! $isMine,
                ])>
                    <p class="whitespace-pre-wrap break-words leading-relaxed">{{ $message->body }}</p>
                    <p @class([
                        'mt-1 text-end text-[10px] tracking-wide',
                        'text-white/70' => $isMine,
                        'text-gray-400' => ! $isMine,
                    ])>{{ $message->created_at->translatedFormat('g:i A') }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{--
        The message shows in the list and the input clears the instant Send
        is pressed; the server's reply then swaps the grey copy for the real
        one. Waiting on the round trip first made every send feel seconds
        slow on shared hosting.
    --}}
    <form
        x-data="{
            send() {
                const input = this.$refs.input;
                const text = input.value.trim();
                if (text === '') { return; }
                input.value = '';
                this.$wire.body = '';
                const bubble = this.$refs.pending.content.firstElementChild.cloneNode(true);
                bubble.querySelector('[data-text]').textContent = text;
                this.$el.closest('.fi-chat-widget').querySelector('[data-chat-messages]')?.appendChild(bubble);
                window.dispatchEvent(new CustomEvent('message-sent'));
                this.$wire.sendMessage(text);
            },
        }"
        x-on:submit.prevent="send()"
        class="flex items-center gap-2 border-t border-gray-100 p-3 dark:border-white/10"
    >
        <template x-ref="pending">
            <div class="flex justify-end" style="opacity: 0.6;">
                <div class="max-w-[80%] rounded-2xl rounded-br-md bg-gradient-to-br from-primary-600 to-primary-500 px-4 py-2 text-sm text-white shadow-sm">
                    <p data-text class="whitespace-pre-wrap break-words leading-relaxed"></p>
                    <p class="mt-1 text-end text-[10px] tracking-wide text-white/70">{{ __('Sending...') }}</p>
                </div>
            </div>
        </template>
        <input
            type="text"
            x-ref="input"
            wire:model="body"
            autocomplete="off"
            placeholder="{{ __('Type a message...') }}"
            class="fi-input block w-full rounded-full border-none bg-gray-100 px-4 py-2.5 text-sm text-gray-950 shadow-sm ring-1 ring-transparent transition focus:bg-white focus:ring-2 focus:ring-primary-500 dark:bg-white/5 dark:text-white dark:focus:bg-white/10"
        />
        <button
            type="submit"
            class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-primary-600 text-white shadow-sm transition hover:bg-primary-500 disabled:opacity-60"
            aria-label="{{ __('Send') }}"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4 rtl:-scale-x-100">
                <path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z" />
            </svg>
        </button>
    </form>
@else
    <div class="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" class="h-10 w-10 text-gray-300 dark:text-gray-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
        </svg>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Pick a conversation, or search a colleague to start one.') }}</p>
    </div>
@endif
