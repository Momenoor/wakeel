{{--
    A sound for each new notification (a soft chime) and chat message (a
    short pop) — on the same "wakeel-desktop-notification" event the desktop
    notifications use (NotificationPoller, ChatWidget), whether or not
    Wakeel is in front.

    - The sound files in public/sounds (notification.mp3, and chat.mp3 if
      there is one); a built-in tone (a WAV made in the page) when there
      is none.
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
        let lastPlayed = 0;
        const files = @js($sounds);
        const players = {};

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

        // The built-in tone, written once as a small WAV and played like a
        // sound file. Not through Web Audio: a live AudioContext took an
        // iPhone's sound over, and a voice note in the chat then played
        // silent, its bar running, until it was sought.
        const tone = (notes) => {
            const rate = 22050;
            const length = Math.ceil(rate * Math.max(...notes.map(([, start, len]) => start + len + 0.02)));
            const samples = new Float32Array(length);
            notes.forEach(([frequency, start, len, volume]) => {
                const from = Math.floor(start * rate);
                const count = Math.floor(len * rate);
                const attack = 0.015 * rate;
                for (let i = 0; i < count && from + i < length; i++) {
                    // As the oscillator did: up in 15ms, then away to nothing.
                    const level = i < attack
                        ? 0.0001 * Math.pow(volume / 0.0001, i / attack)
                        : volume * Math.pow(0.0001 / volume, (i - attack) / (count - attack));
                    samples[from + i] += level * Math.sin(2 * Math.PI * frequency * i / rate);
                }
            });
            const view = new DataView(new ArrayBuffer(44 + length * 2));
            const text = (at, s) => [...s].forEach((c, i) => view.setUint8(at + i, c.charCodeAt(0)));
            text(0, 'RIFF'); view.setUint32(4, 36 + length * 2, true); text(8, 'WAVE');
            text(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
            view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
            text(36, 'data'); view.setUint32(40, length * 2, true);
            samples.forEach((v, i) => view.setInt16(44 + i * 2, Math.max(-1, Math.min(1, v)) * 0x7fff, true));
            return URL.createObjectURL(new Blob([view], { type: 'audio/wav' }));
        };
        const tones = {
            chat: () => tone([[660, 0, 0.12, 0.18], [990, 0.07, 0.16, 0.14]]),
            notification: () => tone([[880, 0, 0.35, 0.16], [1318.5, 0.16, 0.5, 0.12]]),
        };

        return {
            play(kind, force = false) {
                if (! force && ! enabled()) { return; }
                // A burst of notifications is one sound.
                if (! force && Date.now() - lastPlayed < 1500) { return; }
                lastPlayed = Date.now();

                // The office's sound file, else the built-in tone. Browsers
                // only allow it once the page has been used.
                players[kind] ??= new Audio(files[kind] || tones[kind]());
                players[kind].currentTime = 0;
                players[kind].play().catch(() => {});
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
