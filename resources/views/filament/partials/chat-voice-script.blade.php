{{--
    A voice note in the chat, played as WhatsApp does (chat-voice-note):
    its waveform drawn from the sound itself, the part heard filled in as it
    plays, tapped or dragged to go elsewhere in it; its length, then the time
    reached; 1×, 1.5×, 2×. One plays at a time.

    The length comes from the decoded sound: a note recorded in the browser
    (WebM) carries none, and the plain player showed 0:00 or ∞.
--}}
<script>
    document.addEventListener('alpine:init', () => {
        const BARS = 40;
        const peaksCache = new Map();
        const speeds = [1, 1.5, 2];

        // Seeded bars, the same each time for a note, until (or unless) its
        // sound is read.
        const seeded = (seed) => {
            let x = seed || 1;
            return Array.from({ length: BARS }, () => {
                x = (x * 9301 + 49297) % 233280;
                return 0.25 + (x / 233280) * 0.6;
            });
        };

        const time = (seconds) => {
            if (! isFinite(seconds) || seconds < 0) {
                return '0:00';
            }
            const s = Math.round(seconds);
            return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        };

        Alpine.data('wkVoice', (url, seed) => ({
            audio: null,
            peaks: seeded(seed),
            duration: 0,
            current: 0,
            playing: false,
            speed: 0,
            seeking: false,

            init() {
                // In the page, played inline: Safari on a phone wants both.
                this.audio = document.createElement('audio');
                this.audio.preload = 'metadata';
                this.audio.setAttribute('playsinline', '');
                this.audio.style.display = 'none';
                this.audio.src = url;
                this.$el.appendChild(this.audio);
                this.audio.addEventListener('loadedmetadata', () => {
                    if (isFinite(this.audio.duration) && this.audio.duration > 0 && ! this.duration) {
                        this.duration = this.audio.duration;
                    }
                });
                this.audio.addEventListener('timeupdate', () => {
                    if (! this.seeking) {
                        this.current = this.audio.currentTime;
                    }
                });
                this.audio.addEventListener('ended', () => {
                    this.playing = false;
                    this.current = 0;
                });
                this.audio.addEventListener('pause', () => { this.playing = false; });
                this.audio.addEventListener('play', () => { this.playing = true; });

                // Another note started: this one stops.
                window.addEventListener('wk-voice-play', (e) => {
                    if (e.detail !== this) {
                        this.audio.pause();
                    }
                });

                this.read();
            },

            destroy() {
                this.audio?.pause();
            },

            // The sound read once: its real length and its waveform.
            async read() {
                if (peaksCache.has(url)) {
                    const known = peaksCache.get(url);
                    this.peaks = known.peaks;
                    this.duration = known.duration;
                    return;
                }
                try {
                    const data = await (await fetch(url, { credentials: 'same-origin' })).arrayBuffer();
                    // Read offline — no sound out: a live AudioContext took the
                    // phone's sound over (iPhone), and the note then played
                    // silent until it was sought.
                    const Offline = window.OfflineAudioContext || window.webkitOfflineAudioContext;
                    if (! Offline) {
                        return;
                    }
                    const buffer = await new Promise((resolve, reject) => {
                        const decoding = new Offline(1, 1, 44100).decodeAudioData(data, resolve, reject);
                        decoding?.catch?.(reject);
                    });
                    const samples = buffer.getChannelData(0);
                    const block = Math.max(1, Math.floor(samples.length / BARS));
                    const raw = Array.from({ length: BARS }, (_, i) => {
                        let sum = 0;
                        for (let j = i * block; j < Math.min(samples.length, (i + 1) * block); j++) {
                            sum += Math.abs(samples[j]);
                        }
                        return sum / block;
                    });
                    const top = Math.max(...raw) || 1;
                    const peaks = raw.map((v) => 0.15 + 0.85 * (v / top));
                    peaksCache.set(url, { peaks, duration: buffer.duration });
                    this.peaks = peaks;
                    this.duration = buffer.duration;
                } catch (e) {
                    // Not readable here: the seeded bars, the player's length.
                }
            },

            toggle() {
                if (this.playing) {
                    this.audio.pause();
                    return;
                }
                window.dispatchEvent(new CustomEvent('wk-voice-play', { detail: this }));
                // Nothing loaded yet: loaded first, so it starts with its sound.
                if (this.audio.readyState === 0) {
                    this.audio.load();
                }
                this.audio.playbackRate = speeds[this.speed];
                this.audio.play().catch(() => {});
            },

            nextSpeed() {
                this.speed = (this.speed + 1) % speeds.length;
                this.audio.playbackRate = speeds[this.speed];
            },

            get speedLabel() {
                return speeds[this.speed] + '×';
            },

            get progress() {
                return this.duration ? Math.min(1, this.current / this.duration) : 0;
            },

            get label() {
                return time(this.playing || this.current > 0 ? this.current : this.duration);
            },

            // A tap or a drag on the bars: there in the note.
            seekTo(e) {
                const r = e.currentTarget.getBoundingClientRect();
                const rtl = getComputedStyle(e.currentTarget).direction === 'rtl';
                let at = (e.clientX - r.left) / r.width;
                at = Math.min(1, Math.max(0, rtl ? 1 - at : at));
                if (this.duration) {
                    this.current = at * this.duration;
                    this.audio.currentTime = this.current;
                }
            },
            startSeek(e) {
                this.seeking = true;
                e.currentTarget.setPointerCapture?.(e.pointerId);
                this.seekTo(e);
            },
            moveSeek(e) {
                if (this.seeking) {
                    this.seekTo(e);
                }
            },
            endSeek() {
                this.seeking = false;
            },
        }));
    });
</script>
