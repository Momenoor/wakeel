@if (! $isPopup)
    @php($other = $this->activeConversation && ! $this->activeConversation->is_group ? $this->otherParticipant($this->activeConversation) : null)
    <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/10">
        @if ($this->activeConversation?->is_group)
            @include('livewire.partials.chat-group-avatar', ['conversation' => $this->activeConversation, 'size' => 36])
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="truncate font-semibold text-gray-950 dark:text-white">{{ $this->activeConversation->name }}</span>
                <span class="truncate text-xs text-gray-400">{{ trans_choice(':count member|:count members', $this->activeConversation->participants->count()) }}</span>
            </span>
            <span class="text-gray-500"><button type="button" wire:click="toggleMembers" title="{{ __('Members') }}" aria-label="{{ __('Members') }}" style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; flex-shrink: 0; border-radius: 9999px;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </button></span>
        @elseif ($other)
            @include('livewire.partials.chat-avatar', ['user' => $other, 'size' => 36])
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="truncate font-semibold text-gray-950 dark:text-white">{{ $other->display_name ?: $other->name }}</span>
                {{-- Live like the avatar dot — see the online-status style in chat-widget.blade.php --}}
                <span
                    data-chat-status="{{ $other->id }}"
                    data-online="{{ __('Online') }}"
                    data-offline="{{ $other->last_seen_at ? __('Last seen :time', ['time' => $other->last_seen_at->diffForHumans()]) : __('Offline') }}"
                    data-online-green
                    class="text-xs text-gray-400"
                ></span>
            </span>
        @else
            <span class="font-semibold text-gray-500 dark:text-gray-400">{{ __('Chat') }}</span>
        @endif
    </div>
@endif

@if ($this->activeConversation)
    @if ($this->activeConversation->is_group && $showMembers)
        @include('livewire.partials.chat-group-panel')
    @endif
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
            preview: null,
            stick: true,
            {{-- The one reaction picker, for the message whose button was pressed. --}}
            picker: null,
            toBottom() {
                this.$el.scrollTop = this.$el.scrollHeight;
            },
            follow() {
                if (this.stick) { this.toBottom(); }
            },
            pick(id, event) {
                const r = event.currentTarget.getBoundingClientRect();
                this.picker = this.picker?.id === id ? null : { id, x: Math.max(8, Math.min(r.left - 120, window.innerWidth - 300)), y: Math.max(8, r.top - 52) };
            },
            react(emoji) {
                this.$wire.react(this.picker.id, emoji);
                this.picker = null;
            },
            jump(id) {
                const el = document.getElementById('chat-msg-' + id);
                if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.style.backgroundColor = 'rgba(250, 204, 21, .25)'; setTimeout(() => el.style.backgroundColor = '', 1200); }
            },
            {{-- Older messages above, where the reader is kept. --}}
            earlier() {
                const fromBottom = this.$el.scrollHeight - this.$el.scrollTop;
                this.$wire.loadEarlier().then(() => this.$nextTick(() => { this.$el.scrollTop = this.$el.scrollHeight - fromBottom; }));
            },
        }"
        x-init="
            {{-- From a notification: at its message, flashed; otherwise the newest. --}}
            const focus = document.getElementById('chat-msg-' + @js($this->focusMessageId));
            if (focus) {
                stick = false;
                $nextTick(() => { focus.scrollIntoView({ block: 'center' }); focus.style.backgroundColor = 'rgba(250, 204, 21, .3)'; setTimeout(() => focus.style.backgroundColor = '', 2500); });
                $wire.set('focusMessageId', null, false);
            } else {
                toBottom();
            }
            new MutationObserver(() => follow()).observe($el, { childList: true, subtree: true, characterData: true });
            new ResizeObserver(() => follow()).observe($el);
        "
        x-on:scroll="stick = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 80"
        x-on:message-sent.window="stick = true; toBottom()"
        x-on:chat-preview="preview = $event.detail"
        x-on:keydown.escape.window="preview = null; picker = null"
        x-on:scroll.passive="picker = null"
        class="flex-1 space-y-3 overflow-y-auto p-4"
    >
        @include('livewire.partials.chat-styles')

        @if ($this->hasEarlierMessages)
            <div class="flex justify-center">
                <button type="button" x-on:click="earlier()" wire:loading.attr="disabled" wire:target="loadEarlier" class="wk-earlier">
                    <span wire:loading.remove wire:target="loadEarlier">{{ __('Show earlier messages') }}</span>
                    <span wire:loading wire:target="loadEarlier">{{ __('Loading…') }}</span>
                </button>
            </div>
        @endif

        {{-- The reactions to pick from, over the message whose button was pressed. --}}
        <div x-show="picker" x-cloak x-transition.opacity x-on:click.outside="picker = null" class="wk-picker" x-bind:style="picker ? 'left:' + picker.x + 'px; top:' + picker.y + 'px' : ''">
            @foreach (\App\Models\ChatMessage::REACTIONS as $emoji)
                <button type="button" x-on:click="react(@js($emoji))" aria-label="{{ $emoji }}">{{ $emoji }}</button>
            @endforeach
        </div>
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
            {{-- Its tools (reply, react, delete) beside the bubble, shown on hover
                 by CSS (wk-chat-styles) — no script of their own per message. --}}
            <div
                id="chat-msg-{{ $message->id }}"
                wire:key="chat-msg-{{ $message->id }}"
                @class(['wk-msg', 'mine' => $isMine])
            >
                @php($tools = '<button type="button" class="wk-tool" wire:click="replyTo('.$message->id.')" title="'.e(__('Reply')).'" aria-label="'.e(__('Reply')).'"><svg class="wk-flip"><use href="#wk-i-reply"/></svg></button>'
                    .'<button type="button" class="wk-tool" x-on:click.stop="pick('.$message->id.', $event)" title="'.e(__('React')).'" aria-label="'.e(__('React')).'"><svg><use href="#wk-i-react"/></svg></button>'
                    .($this->canDelete($message) ? '<button type="button" class="wk-tool del" wire:click="deleteMessage('.$message->id.')" wire:confirm="'.e(__('Delete this message for everyone?')).'" title="'.e(__('Delete')).'" aria-label="'.e(__('Delete')).'"><svg><use href="#wk-i-delete"/></svg></button>' : ''))
                @if ($isMine) {!! $tools !!} @endif
                <div @class([
                    'max-w-[80%] rounded-2xl px-4 py-2 text-sm shadow-sm',
                    'rounded-br-md bg-gradient-to-br from-primary-600 to-primary-500 text-white' => $isMine,
                    'rounded-bl-md bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white' => ! $isMine,
                ]) style="min-width: 0;">
                    {{-- In a group: who wrote it, above the first of theirs in a row. --}}
                    @if (! $isMine && $this->activeConversation->is_group && ($previousSender ?? null) !== $message->user_id)
                        <p class="wk-sender">{{ $message->sender?->display_name ?: $message->sender?->name }}</p>
                    @endif
                    {{-- The message answered: tap to go to it. --}}
                    @if ($message->replyTo)
                        <button type="button" class="wk-quote" x-on:click="jump({{ $message->reply_to_id }})">
                            <b>{{ $message->replyTo->user_id === auth()->id() ? __('You') : ($message->replyTo->sender?->display_name ?: $message->replyTo->sender?->name) }}</b>
                            <span>{{ $message->replyTo->preview(90) }}</span>
                        </button>
                    @endif

                    {{-- Files: pictures shown, the rest as a link to download. --}}
                    @foreach ($message->files() as $index => $file)
                        @php($url = route('chat.attachment', [$message, $index]))
                        @if (\App\Models\ChatMessage::isAudio($file))
                            {{-- A voice note (or sound file): played here. --}}
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                                @if (! empty($file['voice']))<span aria-hidden="true">🎤</span>@endif
                                <audio controls preload="metadata" src="{{ $url }}" style="height: 36px; max-width: 240px;"></audio>
                            </div>
                        @elseif (\App\Models\ChatMessage::isVideo($file))
                            <div style="position: relative; margin-bottom: 6px;">
                                <video controls preload="metadata" src="{{ $url }}" style="display: block; max-width: 100%; max-height: 260px; border-radius: 10px;"></video>
                                <button type="button" x-on:click="$dispatch('chat-preview', { type: 'video', url: @js($url), name: @js($file['name']) })" title="{{ __('Enlarge') }}" aria-label="{{ __('Enlarge') }}" style="position: absolute; top: 6px; inset-inline-end: 6px; width: 28px; height: 28px; border-radius: 9999px; background: rgba(0,0,0,.55); color: #fff; font-size: .9rem; line-height: 1;">⤢</button>
                            </div>
                        @elseif (\App\Models\ChatMessage::isImage($file) && $file['mime'] !== 'image/svg+xml')
                            <button type="button" x-on:click="$dispatch('chat-preview', { type: 'image', url: @js($url), name: @js($file['name']) })" style="display: block; margin-bottom: 6px; padding: 0; cursor: zoom-in;">
                                <img src="{{ $url }}" alt="{{ $file['name'] }}" loading="lazy" style="display: block; max-width: 100%; max-height: 240px; border-radius: 10px; object-fit: cover;">
                            </button>
                        @else
                            {{-- A PDF previews here; any other file downloads. --}}
                            <a href="{{ $url }}?download=1" @if (($file['mime'] ?? '') === 'application/pdf') x-on:click.prevent="$dispatch('chat-preview', { type: 'pdf', url: @js($url), name: @js($file['name']) })" @endif class="wk-file">
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink: 0;"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>
                                <span style="min-width: 0;">
                                    <span dir="auto" style="display: block; font-size: .8rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $file['name'] }}</span>
                                    <span style="display: block; font-size: .7rem; opacity: .75;">{{ \Illuminate\Support\Number::fileSize((int) $file['size']) }}</span>
                                </span>
                            </a>
                        @endif
                    @endforeach

                    {{-- Escaped, with its links (and emails) clickable. --}}
                    @if (trim((string) $message->body) !== '')
                        <p class="whitespace-pre-wrap break-words leading-relaxed">{!! \App\Support\Linkify::html($message->body) !!}</p>
                    @endif
                    <p class="wk-meta">
                        <span>{{ $message->created_at->translatedFormat('g:i A') }}</span>
                        {{-- Mine: one tick sent, two delivered, two coloured read. --}}
                        @if ($isMine)
                            @php($status = $this->messageStatus($message))
                            <span data-status="{{ $status }}" title="{{ ['sent' => __('Sent'), 'delivered' => __('Delivered'), 'read' => __('Read')][$status] }}" @class(['wk-ticks', 'read' => $status === 'read'])><svg><use href="#wk-i-{{ $status === 'sent' ? 'tick' : 'ticks' }}"/></svg></span>
                        @endif
                    </p>
                    {{-- Its reactions: each emoji with how many; tap one to give (or take back) it. --}}
                    @if ($groups = $message->reactionGroups())
                        <div class="wk-reactions">
                            @foreach ($groups as $emoji => $userIds)
                                <button
                                    type="button"
                                    wire:click="react({{ $message->id }}, @js($emoji))"
                                    title="{{ $this->reactorNames($userIds) }}"
                                    data-reaction="{{ $emoji }}"
                                    @class(['wk-reaction', 'own' => in_array(auth()->id(), $userIds, true)])
                                >{{ $emoji }}@if (count($userIds) > 1)<small>{{ count($userIds) }}</small>@endif</button>
                            @endforeach
                        </div>
                    @endif
                </div>
                @unless ($isMine) {!! $tools !!} @endunless
            </div>
            @php($previousSender = $message->user_id)
        @endforeach

        {{-- A picture, PDF or video, previewed over the page — not opened in
             a new one. Moved to <body>: inside the chat's own box it would be
             cut to its size. --}}
        <template x-teleport="body">
            <div
                x-show="preview"
                x-cloak
                x-transition.opacity
                style="position: fixed; inset: 0; z-index: 9999; background: rgba(0, 0, 0, .85);"
            >
              {{-- The layout on an inner box: x-show replaces the outer one's display. --}}
              <div x-on:click.self="preview = null" style="height: 100%; display: flex; flex-direction: column;">
                <div style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; color: #fff;">
                    <span dir="auto" style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .9rem;" x-text="preview?.name"></span>
                    <a x-bind:href="preview ? preview.url + '?download=1' : '#'" style="padding: 6px 12px; border-radius: 9999px; background: rgba(255,255,255,.15); color: #fff; font-size: .8rem; text-decoration: none;">{{ __('Download') }}</a>
                    <button type="button" x-on:click="preview = null" aria-label="{{ __('Close') }}" style="width: 36px; height: 36px; border-radius: 9999px; background: rgba(255,255,255,.15); color: #fff; font-size: 1.3rem; line-height: 1;">×</button>
                </div>
                <div x-on:click.self="preview = null" style="flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; padding: 0 14px 14px;">
                    <template x-if="preview?.type === 'image'">
                        <img x-bind:src="preview.url" x-bind:alt="preview.name" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px;">
                    </template>
                    <template x-if="preview?.type === 'video'">
                        <video x-bind:src="preview.url" controls autoplay style="max-width: 100%; max-height: 100%; border-radius: 6px; background: #000;"></video>
                    </template>
                    <template x-if="preview?.type === 'pdf'">
                        <iframe x-bind:src="preview.url" x-bind:title="preview.name" style="width: min(100%, 960px); align-self: stretch; border: 0; border-radius: 6px; background: #fff;"></iframe>
                    </template>
                </div>
              </div>
            </div>
        </template>
    </div>

    {{--
        The message shows in the list and the input clears the instant Send
        is pressed; the server's reply then swaps the grey copy for the real
        one. Waiting on the round trip first made every send feel seconds
        slow on shared hosting.
    --}}
    {{-- The message being answered, and the files picked: until sent. --}}
    @if ($this->replyingTo)
        <div style="display: flex; align-items: center; gap: 8px; margin: 8px 12px 0; padding: 6px 10px; border-radius: 10px; border-inline-start: 3px solid rgb(37 99 235); background: rgba(37, 99, 235, .08); font-size: .75rem;">
            <span style="min-width: 0; flex: 1;">
                <span style="display: block; font-weight: 600; color: rgb(37 99 235);">{{ __('Replying to :name', ['name' => $this->replyingTo->user_id === auth()->id() ? __('yourself') : ($this->replyingTo->sender?->display_name ?: $this->replyingTo->sender?->name)]) }}</span>
                <span style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: .8;">{{ $this->replyingTo->preview(90) }}</span>
            </span>
            <button type="button" wire:click="cancelReply" aria-label="{{ __('Cancel') }}" style="padding: 2px 6px; font-size: 1rem; line-height: 1; opacity: .6;">×</button>
        </div>
    @endif
    {{--
        Files are kept here, in the browser, until Send: then the text and
        the files go up together in one request (ChatMessageController) —
        its progress shown — and the list shows the message. A text alone
        goes the quick way below.
    --}}
    <div x-data="{
        files: [],
        error: '',
        progress: null,
        recording: false,
        seconds: 0,
        timer: null,
        // A voice note: recorded here, sent as a file on Send.
        async record() {
            this.error = '';
            if (! navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
                this.error = @js(__('This browser cannot record sound.'));
                return;
            }
            let stream;
            try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
            catch (e) { this.error = @js(__('The microphone is not allowed. Allow it in the browser to record.')); return; }
            const type = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm'].find((t) => MediaRecorder.isTypeSupported?.(t)) || '';
            // Kept outside Alpine's state: the browser's recorder doesn't
            // work through its proxies.
            const voice = window.wakeelVoice = { recorder: new MediaRecorder(stream, type ? { mimeType: type } : {}), chunks: [] };
            voice.recorder.ondataavailable = (e) => { if (e.data.size) { voice.chunks.push(e.data); } };
            voice.recorder.start();
            this.recording = true;
            this.seconds = 0;
            this.timer = setInterval(() => { this.seconds++; if (this.seconds >= 300) { this.stopRecording(true); } }, 1000);
        },
        stopRecording(send) {
            const voice = window.wakeelVoice;
            const recorder = voice?.recorder;
            if (! recorder) { return; }
            clearInterval(this.timer);
            window.wakeelVoice = null;
            this.recording = false;
            recorder.onstop = () => {
                recorder.stream.getTracks().forEach((t) => t.stop());
                if (! send || ! voice.chunks.length) { return; }
                const type = recorder.mimeType || 'audio/webm';
                const ext = type.includes('mp4') ? 'm4a' : (type.includes('ogg') ? 'ogg' : 'webm');
                const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
                const file = new File([new Blob(voice.chunks, { type })], 'voice-note-' + stamp + '.' + ext, { type });
                this.sendFiles('', [file], true);
            };
            recorder.stop();
        },
        clock() { return Math.floor(this.seconds / 60) + ':' + String(this.seconds % 60).padStart(2, '0'); },
        pick(event) {
            this.error = '';
            for (const file of event.target.files) {
                if (this.files.length >= {{ \App\Services\Chat\ChatMessenger::MAX_FILES }}) {
                    this.error = @js(__('Up to :count files at a time.', ['count' => \App\Services\Chat\ChatMessenger::MAX_FILES]));
                    break;
                }
                if (file.size > {{ \App\Services\Chat\ChatMessenger::MAX_KB * 1024 }}) {
                    this.error = @js(__(':name is larger than 20 MB.')).replace(':name', file.name);
                    continue;
                }
                this.files.push(file);
            }
            event.target.value = '';
        },
        sendFiles(text, files = null, voice = false) {
            const data = new FormData();
            data.append('body', text);
            if (voice) { data.append('voice', '1'); }
            if (this.$wire.replyToId) { data.append('reply_to_id', this.$wire.replyToId); }
            (files || this.files).forEach((file) => data.append('files[]', file, file.name));

            const request = new XMLHttpRequest();
            request.open('POST', @js(route('chat.messages.store', $this->activeConversation)));
            request.setRequestHeader('X-CSRF-TOKEN', @js(csrf_token()));
            request.setRequestHeader('Accept', 'application/json');
            request.upload.onprogress = (e) => { if (e.lengthComputable) { this.progress = Math.round(e.loaded * 100 / e.total); } };
            request.onload = () => {
                this.progress = null;
                if (request.status >= 200 && request.status < 300) {
                    if (! voice) {
                        this.files = [];
                        this.$refs.input.value = '';
                        this.$wire.body = '';
                    }
                    window.dispatchEvent(new CustomEvent('message-sent'));
                    this.$wire.sentWithFiles();
                    return;
                }
                let message = null;
                try { message = JSON.parse(request.responseText).message; } catch (e) {}
                this.error = request.status === 413 ? @js(__('The files are too large for the server.')) : (message || @js(__('The files could not be sent. Try again.')));
            };
            request.onerror = () => { this.progress = null; this.error = @js(__('The files could not be sent. Try again.')); };
            this.progress = 0;
            request.send(data);
        },
        send() {
            const input = this.$refs.input;
            const text = input.value.trim();
            if (this.files.length) {
                if (this.progress === null) { this.sendFiles(text); }
                return;
            }
            if (text === '') { return; }
            input.value = '';
            this.$wire.body = '';
            const bubble = this.$refs.pending.content.firstElementChild.cloneNode(true);
            bubble.querySelector('[data-text]').textContent = text;
            this.$el.closest('.fi-chat-widget').querySelector('[data-chat-messages]')?.appendChild(bubble);
            window.dispatchEvent(new CustomEvent('message-sent'));
            this.$wire.sendMessage(text);
        },
    }">
        <template x-if="files.length">
            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 12px 0;">
                <template x-for="(file, index) in files" :key="index">
                    <span style="display: inline-flex; align-items: center; gap: 4px; max-width: 100%; padding: 3px 4px 3px 10px; border-radius: 9999px; background: rgba(107, 114, 128, .12); font-size: .75rem;">
                        <span dir="auto" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 12rem;" x-text="'📎 ' + file.name"></span>
                        <button type="button" x-on:click="files.splice(index, 1)" x-bind:disabled="progress !== null" aria-label="{{ __('Remove') }}" style="padding: 0 6px; font-size: .9rem; line-height: 1; opacity: .6;">×</button>
                    </span>
                </template>
            </div>
        </template>
        <p x-show="error" x-text="error" style="margin: 6px 12px 0; font-size: .75rem; color: rgb(220 38 38);"></p>
        <div x-show="progress !== null" style="margin: 6px 12px 0; font-size: .75rem;">
            <span>{{ __('Uploading…') }} <span x-text="progress + '%'"></span></span>
            <div style="height: 4px; margin-top: 3px; border-radius: 9999px; background: rgba(107, 114, 128, .2); overflow: hidden;">
                <div x-bind:style="'height: 100%; background: rgb(37 99 235); width: ' + (progress || 0) + '%'"></div>
            </div>
        </div>

        {{-- While recording: the time, cancel, and send. --}}
        <div x-show="recording" x-cloak style="display: flex; align-items: center; gap: 10px; margin: 8px 12px 0; padding: 6px 12px; border-radius: 9999px; background: rgba(220, 38, 38, .08); font-size: .85rem;">
            <span style="width: 10px; height: 10px; border-radius: 9999px; background: rgb(220 38 38); animation: pulse 1s infinite;"></span>
            <span style="flex: 1;">{{ __('Recording…') }} <span dir="ltr" x-text="clock()"></span></span>
            <button type="button" x-on:click="stopRecording(false)" style="font-size: .8rem; opacity: .7;">{{ __('Cancel') }}</button>
            <button type="button" x-on:click="stopRecording(true)" class="rounded-full bg-primary-600 px-3 py-1 text-xs font-semibold text-white">{{ __('Send') }}</button>
        </div>

        <form
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
            {{-- Files to send with the message (each up to 20 MB, five at a time). --}}
            <label
                title="{{ __('Attach files') }}"
                style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex-shrink: 0; border-radius: 9999px; cursor: pointer; color: rgb(107 114 128);"
            >
                <input type="file" multiple x-on:change="pick($event)" style="display: none;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
            </label>
            <input
                type="text"
                x-ref="input"
                wire:model="body"
                autocomplete="off"
                placeholder="{{ __('Type a message...') }}"
                class="fi-input block w-full rounded-full border-none bg-gray-100 px-4 py-2.5 text-sm text-gray-950 shadow-sm ring-1 ring-transparent transition focus:bg-white focus:ring-2 focus:ring-primary-500 dark:bg-white/5 dark:text-white dark:focus:bg-white/10"
            />
            {{-- A voice note. --}}
            <button
                type="button"
                x-on:click="record()"
                x-bind:disabled="recording || progress !== null"
                title="{{ __('Record a voice note') }}"
                aria-label="{{ __('Record a voice note') }}"
                style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex-shrink: 0; border-radius: 9999px; color: rgb(107 114 128);"
            >
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5"/></svg>
            </button>
            <button
                type="submit"
                x-bind:disabled="progress !== null"
                class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-primary-600 text-white shadow-sm transition hover:bg-primary-500 disabled:opacity-60"
                aria-label="{{ __('Send') }}"
            >
                {{-- Points toward the end side: right in English, left in Arabic. --}}
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4" @if (__('filament-panels::layout.direction') === 'rtl') style="transform: scaleX(-1)" @endif>
                    <path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z" />
                </svg>
            </button>
        </form>
    </div>
@else
    <div class="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" class="h-10 w-10 text-gray-300 dark:text-gray-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
        </svg>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Pick a conversation, or search a colleague to start one.') }}</p>
    </div>
@endif
