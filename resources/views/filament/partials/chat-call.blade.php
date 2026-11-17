{{--
    Voice calls in the chat (App\Services\Chat\ChatCalls), on every page for whoever may chat: the call button of
    a one-to-one conversation (chat-body, chat-thread) dispatches "wk-call"; a call coming in rings here, on
    whichever page is open, from the user's own channel (".chat.call").

    The two browsers talk to each other (WebRTC). Each one's offer or answer is made whole — its ways to be
    reached gathered first — then kept on the server for the other to read; the channel only says "ring",
    "answered", "ended". An answered call says it goes on every 20 seconds (ping); a page left, or closed, ends it.

    Sounds are small WAV tones made here, played as files — not through Web Audio (see notification-sound).
--}}
@php
    $callConfig = [
        'userId' => (int) auth()->id(),
        'token' => csrf_token(),
        'ice' => route('chat.calls.ice'),
        'current' => route('chat.calls.current'),
        'start' => route('chat.calls.store', ['conversation' => '__CONV__']),
        'calls' => url('/chat/calls'),
        't' => [
            'calling' => __('Calling…'),
            'ringing' => __('Ringing…'),
            'incoming' => __('Incoming voice call'),
            'connecting' => __('Connecting…'),
            'ended' => __('Call ended'),
            'noAnswer' => __('No answer'),
            'missed' => __('Missed voice call'),
            'declined' => __('Declined'),
            'failed' => __('Could not connect'),
            'over' => __('The call has ended.'),
            'mic' => __('The microphone could not be used. Allow it in the browser to call.'),
            'unsupported' => __('Your browser cannot make calls.'),
            'leave' => __('Leaving this page ends the call.'),
        ],
    ];
@endphp
<script>
    (() => {
        const register = () => Alpine.data('wkCall', (cfg) => ({
            state: 'idle', // idle, outgoing, incoming, connecting, active, ended
            role: null, // caller, callee
            callId: null,
            name: '',
            avatar: '',
            status: '',
            muted: false,
            seconds: 0,
            pc: null,
            local: null,
            remote: null,
            tone: null,
            timers: {},

            init() {
                this.remote = new Audio();
                this.remote.autoplay = true;
                this.remote.setAttribute('playsinline', '');

                this.listen();

                // A call already ringing for me when the page opened (I moved to another page meanwhile).
                this.req('GET', cfg.current).then((r) => {
                    if (r.ok && r.data?.call_id && this.state === 'idle') {
                        this.req('GET', cfg.calls + '/' + r.data.call_id).then((c) => {
                            if (c.ok && c.data?.status === 'ringing' && this.state === 'idle') {
                                this.ringing(c.data.id, c.data.name, c.data.avatar);
                            }
                        });
                    }
                });

                // A page left mid-call ends it; asked first, while one is on.
                window.addEventListener('beforeunload', (e) => {
                    if (['outgoing', 'connecting', 'active'].includes(this.state)) {
                        e.preventDefault();
                        e.returnValue = cfg.t.leave;
                    }
                });
                window.addEventListener('pagehide', () => {
                    if (this.callId && ['outgoing', 'connecting', 'active'].includes(this.state)) {
                        const data = new FormData();
                        data.append('_token', cfg.token);
                        data.append('reason', this.state === 'outgoing' ? 'cancel' : 'hangup');
                        navigator.sendBeacon(cfg.calls + '/' + this.callId + '/end', data);
                    }
                });
            },

            // The user's own channel, once Echo is there (the panel starts it after the page).
            listen(tries = 0) {
                if (! window.Echo) {
                    if (tries < 60) {
                        setTimeout(() => this.listen(tries + 1), 500);
                    }
                    return;
                }
                window.Echo.private('App.Models.User.' + cfg.userId).listen('.chat.call', (e) => this.signal(e));
            },

            req(method, url, body) {
                return fetch(url, {
                    method,
                    credentials: 'same-origin',
                    keepalive: method !== 'GET',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: body ? JSON.stringify(body) : undefined,
                }).then(async (r) => ({ ok: r.ok, status: r.status, data: r.status === 204 ? null : await r.json().catch(() => null) }))
                    .catch(() => ({ ok: false, status: 0, data: null }));
            },

            get live() {
                return this.state !== 'idle';
            },

            get clock() {
                const s = this.seconds;
                const mm = Math.floor((s % 3600) / 60), ss = String(s % 60).padStart(2, '0');
                return s >= 3600 ? Math.floor(s / 3600) + ':' + String(mm).padStart(2, '0') + ':' + ss : mm + ':' + ss;
            },

            // ── Calling ───────────────────────────────────────────────

            async start({ conversationId, name, avatar }) {
                if (this.state !== 'idle') {
                    return;
                }
                if (! window.RTCPeerConnection || ! navigator.mediaDevices?.getUserMedia) {
                    alert(cfg.t.unsupported);
                    return;
                }
                Object.assign(this, { state: 'outgoing', role: 'caller', name, avatar, status: cfg.t.calling, muted: false, seconds: 0 });

                if (! await this.microphone()) {
                    return;
                }

                const offer = await this.connection(async (pc) => {
                    await pc.setLocalDescription(await pc.createOffer());
                });
                if (! offer || this.state !== 'outgoing') {
                    return;
                }

                const r = await this.req('POST', cfg.start.replace('__CONV__', conversationId), { offer });
                if (! r.ok) {
                    this.finish(r.data?.message || cfg.t.failed);
                    return;
                }
                this.callId = r.data.id;
                // Hung up while it was being started.
                if (this.state !== 'outgoing') {
                    this.req('POST', cfg.calls + '/' + this.callId + '/end', { reason: 'cancel' });
                    return;
                }
                this.status = cfg.t.ringing;
                this.play('ringback');
                this.timers.ring = setTimeout(() => this.hangUp('timeout'), (r.data.ring_seconds || 45) * 1000);
            },

            // ── Being called ──────────────────────────────────────────

            ringing(id, name, avatar) {
                Object.assign(this, { state: 'incoming', role: 'callee', callId: id, name, avatar, status: cfg.t.incoming, muted: false, seconds: 0 });
                this.play('ring');
                // Ringing stops by itself if the caller's "ended" never comes.
                this.timers.ring = setTimeout(() => { if (this.state === 'incoming') this.reset(); }, 60000);

                if (document.hidden && window.Notification?.permission === 'granted') {
                    try {
                        const n = new Notification(name, { body: cfg.t.incoming, tag: 'wk-call-' + id, icon: avatar || undefined, requireInteraction: true });
                        n.onclick = () => { window.focus(); n.close(); };
                        this.timers.note = n;
                    } catch (e) {}
                }
            },

            async accept() {
                if (this.state !== 'incoming') {
                    return;
                }
                this.stopTones();
                clearTimeout(this.timers.ring);
                this.state = 'connecting';
                this.status = cfg.t.connecting;

                if (! await this.microphone()) {
                    this.req('POST', cfg.calls + '/' + this.callId + '/end', { reason: 'decline' });
                    return;
                }

                const c = await this.req('GET', cfg.calls + '/' + this.callId);
                if (! c.ok || c.data?.status !== 'ringing' || ! c.data.offer) {
                    this.finish(cfg.t.over);
                    return;
                }

                const answer = await this.connection(async (pc) => {
                    await pc.setRemoteDescription(JSON.parse(c.data.offer));
                    await pc.setLocalDescription(await pc.createAnswer());
                });
                if (! answer || this.state !== 'connecting') {
                    return;
                }

                const r = await this.req('POST', cfg.calls + '/' + this.callId + '/answer', { answer });
                if (! r.ok) {
                    this.finish(r.data?.message || cfg.t.over);
                    return;
                }
                this.connectingTimeout();
            },

            decline() {
                if (this.state === 'incoming') {
                    this.req('POST', cfg.calls + '/' + this.callId + '/end', { reason: 'decline' });
                    this.reset();
                }
            },

            // ── Either side ───────────────────────────────────────────

            async signal(e) {
                if (e.event === 'ring') {
                    // My own call ringing elsewhere, or I am on one already: nothing here.
                    if (e.caller_id !== cfg.userId && this.state === 'idle') {
                        this.ringing(e.call_id, e.name, e.avatar);
                    }
                    return;
                }
                if (e.call_id !== this.callId) {
                    return;
                }
                if (e.event === 'answered') {
                    if (this.role === 'caller' && this.state === 'outgoing') {
                        clearTimeout(this.timers.ring);
                        this.stopTones();
                        this.state = 'connecting';
                        this.status = cfg.t.connecting;
                        const c = await this.req('GET', cfg.calls + '/' + this.callId);
                        if (! c.ok || ! c.data?.answer || ! this.pc) {
                            this.hangUp('failed');
                            return;
                        }
                        try {
                            await this.pc.setRemoteDescription(JSON.parse(c.data.answer));
                        } catch (err) {
                            this.hangUp('failed');
                            return;
                        }
                        this.connectingTimeout();
                    } else if (this.role === 'callee' && this.state === 'incoming') {
                        // Answered in another tab or on another device.
                        this.reset();
                    }
                    return;
                }
                if (e.event === 'ended' && this.state !== 'idle' && this.state !== 'ended') {
                    const words = {
                        ended: cfg.t.ended,
                        missed: this.role === 'caller' ? cfg.t.noAnswer : cfg.t.ended,
                        declined: this.role === 'caller' ? cfg.t.declined : cfg.t.ended,
                        failed: cfg.t.failed,
                    };
                    this.finish(words[e.status] || cfg.t.ended);
                }
            },

            hangUp(reason = null) {
                if (! this.callId) {
                    this.reset();
                    return;
                }
                reason ??= this.state === 'outgoing' ? 'cancel' : 'hangup';
                this.req('POST', cfg.calls + '/' + this.callId + '/end', { reason });
                this.finish(reason === 'timeout' ? cfg.t.noAnswer : (reason === 'failed' ? cfg.t.failed : cfg.t.ended));
            },

            toggleMute() {
                this.muted = ! this.muted;
                this.local?.getAudioTracks().forEach((t) => { t.enabled = ! this.muted; });
            },

            async microphone() {
                try {
                    this.local = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false });
                    return true;
                } catch (e) {
                    this.finish(cfg.t.mic);
                    return false;
                }
            },

            // The connection to the other browser, made with the office's servers; what `make` sets on it
            // returned whole (all its ways to be reached gathered, or after 3 seconds).
            async connection(make) {
                try {
                    const ice = await this.req('GET', cfg.ice);
                    const pc = new RTCPeerConnection({ iceServers: ice.data?.iceServers || [] });
                    this.pc = pc;
                    this.local.getTracks().forEach((t) => pc.addTrack(t, this.local));
                    pc.ontrack = (e) => {
                        this.remote.srcObject = e.streams[0];
                        this.remote.play().catch(() => {});
                    };
                    pc.onconnectionstatechange = () => this.connectionChanged(pc.connectionState);
                    await make(pc);
                    await new Promise((resolve) => {
                        if (pc.iceGatheringState === 'complete') {
                            return resolve();
                        }
                        const t = setTimeout(resolve, 3000);
                        pc.addEventListener('icegatheringstatechange', () => {
                            if (pc.iceGatheringState === 'complete') {
                                clearTimeout(t);
                                resolve();
                            }
                        });
                    });
                    return JSON.stringify(pc.localDescription);
                } catch (e) {
                    if (this.callId) {
                        this.hangUp('failed');
                    } else {
                        this.finish(cfg.t.failed);
                    }
                    return null;
                }
            },

            connectionChanged(s) {
                if (s === 'connected' && this.state === 'connecting') {
                    clearTimeout(this.timers.connect);
                    this.state = 'active';
                    this.status = '';
                    this.timers.clock = setInterval(() => this.seconds++, 1000);
                    this.timers.ping = setInterval(() => this.req('POST', cfg.calls + '/' + this.callId + '/ping'), 20000);
                } else if (s === 'connected') {
                    clearTimeout(this.timers.lost);
                    this.status = '';
                } else if (s === 'disconnected' && this.state === 'active') {
                    // A short loss of network comes back by itself; a long one ends the call.
                    this.status = cfg.t.connecting;
                    this.timers.lost = setTimeout(() => { if (this.pc?.connectionState !== 'connected') this.hangUp('hangup'); }, 10000);
                } else if (s === 'failed') {
                    this.hangUp(this.state === 'active' ? 'hangup' : 'failed');
                }
            },

            connectingTimeout() {
                this.timers.connect = setTimeout(() => { if (this.state === 'connecting') this.hangUp('failed'); }, 20000);
            },

            // Over: said for a moment, then gone.
            finish(words) {
                this.cleanup();
                this.state = 'ended';
                this.status = words;
                setTimeout(() => { if (this.state === 'ended') this.reset(); }, 2500);
            },

            reset() {
                this.cleanup();
                Object.assign(this, { state: 'idle', role: null, callId: null, status: '', muted: false, seconds: 0 });
            },

            cleanup() {
                this.stopTones();
                ['ring', 'connect', 'lost'].forEach((k) => clearTimeout(this.timers[k]));
                ['clock', 'ping'].forEach((k) => clearInterval(this.timers[k]));
                try { this.timers.note?.close(); } catch (e) {}
                this.timers = {};
                try { this.pc?.close(); } catch (e) {}
                this.pc = null;
                this.local?.getTracks().forEach((t) => t.stop());
                this.local = null;
                this.remote.srcObject = null;
            },

            // ── Sounds ────────────────────────────────────────────────

            play(kind) {
                this.stopTones();
                try {
                    this.tone = new Audio(this.wav(kind));
                    this.tone.loop = true;
                    this.tone.volume = kind === 'ring' ? 0.8 : 0.35;
                    this.tone.play().catch(() => {});
                } catch (e) {}
            },

            stopTones() {
                this.tone?.pause();
                this.tone = null;
            },

            // One loop of the tone, as a WAV: a ring (two short double rings) for the one called, a ringback (a
            // long tone, then quiet) for the caller.
            wav(kind) {
                const rate = 16000;
                const parts = kind === 'ring'
                    ? [[[880, 660], 0.4], [null, 0.2], [[880, 660], 0.4], [null, 2.0]]
                    : [[[425], 1.0], [null, 3.0]];
                const total = Math.round(parts.reduce((s, p) => s + p[1], 0) * rate);
                const buf = new DataView(new ArrayBuffer(44 + total * 2));
                const str = (o, s) => [...s].forEach((c, i) => buf.setUint8(o + i, c.charCodeAt(0)));
                str(0, 'RIFF'); buf.setUint32(4, 36 + total * 2, true); str(8, 'WAVEfmt ');
                buf.setUint32(16, 16, true); buf.setUint16(20, 1, true); buf.setUint16(22, 1, true);
                buf.setUint32(24, rate, true); buf.setUint32(28, rate * 2, true); buf.setUint16(32, 2, true); buf.setUint16(34, 16, true);
                str(36, 'data'); buf.setUint32(40, total * 2, true);
                let i = 0;
                for (const [freqs, secs] of parts) {
                    const n = Math.round(secs * rate);
                    for (let k = 0; k < n; k++, i++) {
                        let v = 0;
                        if (freqs) {
                            const fade = Math.min(1, k / 400, (n - k) / 400);
                            v = freqs.reduce((s, f) => s + Math.sin(2 * Math.PI * f * k / rate), 0) / freqs.length * 0.5 * fade;
                        }
                        buf.setInt16(44 + i * 2, v * 32767, true);
                    }
                }
                let bin = '';
                new Uint8Array(buf.buffer).forEach((b) => { bin += String.fromCharCode(b); });
                return 'data:audio/wav;base64,' + btoa(bin);
            },
        }));

        if (window.Alpine) {
            register();
        } else {
            document.addEventListener('alpine:init', register);
        }
    })();
</script>

<div
    x-data="wkCall(@js($callConfig))"
    x-on:wk-call.window="start($event.detail)"
    x-show="live"
    x-cloak
    x-transition.opacity
    class="wk-call"
    role="dialog"
    aria-live="polite"
    style="display: none;"
>
    <style>
        .wk-call { position: fixed; top: 16px; left: 50%; transform: translateX(-50%); z-index: 10000; width: min(340px, calc(100vw - 32px)); }
        .wk-call-card { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 20px 16px 18px; border-radius: 24px; color: #fff; background: linear-gradient(160deg, #111827, #1f2937); box-shadow: 0 20px 50px rgba(0,0,0,.35); }
        .wk-call-avatar { width: 72px; height: 72px; border-radius: 9999px; object-fit: cover; background: rgba(255,255,255,.12); }
        .wk-call-ringing .wk-call-avatar { animation: wk-call-pulse 1.4s ease-out infinite; }
        @keyframes wk-call-pulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.55); } 100% { box-shadow: 0 0 0 18px rgba(34,197,94,0); } }
        .wk-call-name { font-weight: 600; font-size: 1.05rem; text-align: center; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wk-call-status { font-size: .85rem; color: rgba(255,255,255,.75); text-align: center; min-height: 1.2em; }
        .wk-call-buttons { display: flex; gap: 22px; justify-content: center; }
        .wk-call-btn { display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: .72rem; color: rgba(255,255,255,.85); }
        .wk-call-btn span { width: 54px; height: 54px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; transition: transform .15s; }
        .wk-call-btn:hover span { transform: scale(1.06); }
        .wk-call-btn svg { width: 24px; height: 24px; }
        .wk-call-red span { background: #ef4444; }
        .wk-call-green span { background: #22c55e; }
        .wk-call-grey span { background: rgba(255,255,255,.16); }
        .wk-call-grey.on span { background: #fff; color: #111827; }
    </style>
    <div class="wk-call-card" x-bind:class="{ 'wk-call-ringing': state === 'incoming' || state === 'outgoing' }">
        <template x-if="avatar"><img class="wk-call-avatar" x-bind:src="avatar" alt=""></template>
        <template x-if="! avatar"><div class="wk-call-avatar"></div></template>
        <div style="display: flex; flex-direction: column; align-items: center; gap: 2px; max-width: 100%;">
            <div class="wk-call-name" dir="auto" x-text="name"></div>
            <div class="wk-call-status" x-text="state === 'active' && ! status ? clock : status"></div>
        </div>

        <div class="wk-call-buttons">
            {{-- Ringing for me: answer or decline. --}}
            <template x-if="state === 'incoming'">
                <div class="wk-call-buttons">
                    <button type="button" class="wk-call-btn wk-call-red" x-on:click="decline()">
                        <span><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="transform: rotate(135deg);"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg></span>
                        {{ __('Decline') }}
                    </button>
                    <button type="button" class="wk-call-btn wk-call-green" x-on:click="accept()">
                        <span><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg></span>
                        {{ __('Answer the call') }}
                    </button>
                </div>
            </template>

            {{-- Calling, connecting or talking: mute and hang up. --}}
            <template x-if="['outgoing', 'connecting', 'active'].includes(state)">
                <div class="wk-call-buttons">
                    <button type="button" class="wk-call-btn wk-call-grey" x-bind:class="{ on: muted }" x-on:click="toggleMute()">
                        <span>
                            <svg x-show="! muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v1a7 7 0 0 0 14 0v-1M12 18v4"/></svg>
                            <svg x-show="muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 2l20 20M9 9v2a3 3 0 0 0 5.1 2.1M15 9.3V5a3 3 0 0 0-5.9-.7M17 16.9A7 7 0 0 1 5 11v-1M19 10v1a7 7 0 0 1-.1 1.2M12 18v4"/></svg>
                        </span>
                        <span x-text="muted ? @js(__('Unmute')) : @js(__('Mute'))" style="width: auto; height: auto; background: none;"></span>
                    </button>
                    <button type="button" class="wk-call-btn wk-call-red" x-on:click="hangUp()">
                        <span><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="transform: rotate(135deg);"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg></span>
                        {{ __('Hang up') }}
                    </button>
                </div>
            </template>
        </div>
    </div>
</div>
