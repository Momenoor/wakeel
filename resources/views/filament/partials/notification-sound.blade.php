{{--
    A sound for each new notification (a soft chime) and chat message (a
    short pop) — on the same "wakeel-desktop-notification" event the desktop
    notifications use (NotificationPoller, ChatWidget), whether or not
    Wakeel is in front.

    - The sound files in public/sounds (notification.mp3, and chat.mp3 if
      there is one); a built-in Web Audio tone when there is none.
    - One tab plays it: with Wakeel open in several tabs, the first to claim
      it (localStorage) plays; and a burst of notifications is one sound.
    - The speaker button turns sounds off and on for this browser.
    - Browsers only allow sound after the page has been clicked or typed
      in once; until then it stays silent.
--}}
<div
    x-data="{
        on: (() => { try { return localStorage.getItem('wakeel-sound') !== 'off'; } catch (e) { return true; } })(),
        toggle() {
            this.on = ! this.on;
            try { localStorage.setItem('wakeel-sound', this.on ? 'on' : 'off'); } catch (e) {}
            if (this.on) { window.wakeelSound.play('notification', true); }
        },
    }"
    class="flex items-center"
>
    <x-filament::icon-button
        icon="heroicon-o-speaker-wave"
        color="gray"
        :label="__('Turn notification sounds off')"
        :tooltip="__('Turn notification sounds off')"
        x-show="on"
        x-on:click="toggle()"
    />
    <x-filament::icon-button
        icon="heroicon-o-speaker-x-mark"
        color="gray"
        :label="__('Turn notification sounds on')"
        :tooltip="__('Turn notification sounds on')"
        x-show="! on"
        x-cloak
        x-on:click="toggle()"
    />
</div>

@php
    // The office's own sounds (public/sounds): notification.mp3, and
    // chat.mp3 for chat when there is one — else the notification sound.
    // Without a file, a built-in tone.
    $notificationSound = is_file(public_path('sounds/notification.mp3')) ? asset('sounds/notification.mp3') : null;
    $sounds = [
        'notification' => $notificationSound,
        'chat' => is_file(public_path('sounds/chat.mp3')) ? asset('sounds/chat.mp3') : $notificationSound,
    ];
@endphp
<script>
    window.wakeelSound ??= (() => {
        let context = null;
        let lastPlayed = 0;
        const files = @js($sounds);
        const players = {};

        const audio = () => {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (! Context) { return null; }
            context ??= new Context();
            return context;
        };

        // Sound is only allowed once the page has been used.
        const unlock = () => { audio()?.resume(); };
        ['pointerdown', 'keydown', 'touchstart'].forEach((type) => window.addEventListener(type, unlock, { passive: true }));

        const enabled = () => { try { return localStorage.getItem('wakeel-sound') !== 'off'; } catch (e) { return true; } };

        // Only one open tab plays a given notification.
        const claim = (id) => {
            try {
                const key = 'wakeel-sound:' + id;
                const now = Date.now();
                if (now - Number(localStorage.getItem(key) || 0) < 5000) { return false; }
                localStorage.setItem(key, String(now));
            } catch (e) {}
            return true;
        };

        const note = (ctx, frequency, start, length, volume) => {
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(volume, start + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + length);
            oscillator.connect(gain).connect(ctx.destination);
            oscillator.start(start);
            oscillator.stop(start + length + 0.02);
        };

        return {
            play(kind, force = false) {
                if (! force && ! enabled()) { return; }
                // A burst of notifications is one sound.
                if (! force && Date.now() - lastPlayed < 1500) { return; }

                // The office's sound file, when there is one.
                if (files[kind]) {
                    lastPlayed = Date.now();
                    players[kind] ??= new Audio(files[kind]);
                    players[kind].currentTime = 0;
                    players[kind].play().catch(() => {});

                    return;
                }

                const ctx = audio();
                if (! ctx || ctx.state !== 'running') { return; }
                lastPlayed = Date.now();

                const t = ctx.currentTime;
                if (kind === 'chat') {
                    note(ctx, 660, t, 0.12, 0.18);
                    note(ctx, 990, t + 0.07, 0.16, 0.14);
                } else {
                    note(ctx, 880, t, 0.35, 0.16);
                    note(ctx, 1318.5, t + 0.16, 0.5, 0.12);
                }
            },
            notify(detail) {
                const id = String(detail?.id ?? '');
                if (id && ! claim(id)) { return; }
                this.play(id.startsWith('chat-') ? 'chat' : 'notification');
            },
        };
    })();

    if (! window.wakeelSoundListening) {
        window.wakeelSoundListening = true;
        window.addEventListener('wakeel-desktop-notification', (event) => window.wakeelSound.notify(event.detail));
    }
</script>
