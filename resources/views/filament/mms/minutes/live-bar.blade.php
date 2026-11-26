{{--
    At the top of the Record the meeting page: what's typed is saved every two
    seconds when it changed (RecordMinutes::autosaveMinutes, which doesn't
    redraw the page) so the live view the attendees watch keeps up. With
    Pusher, it is on the live view sooner still: a moment after a key is
    pressed (RecordMinutes::previewMinutes), before it is saved.

    A save that failed (no connection, signed out, the server) says so, in
    red, until one goes through: what's typed is still on this page, and
    the next save takes it all.
--}}
<div
    x-data="{
        saving: false,
        failed: false,
        savedAt: null,
        saved: null,
        previewing: false,
        previewed: null,
        previewTimer: null,
        timer: null,
        // What's on the page, to tell whether it changed since it was last sent.
        state() {
            return JSON.stringify($wire.get('data'));
        },
        init() {
            this.saved = this.previewed = this.state();
            this.timer = setInterval(() => this.save(), {{ \App\Events\MinutesLiveUpdated::AUTOSAVE * 1000 }});
            @if ($preview ?? false)
                this.onInput = () => {
                    clearTimeout(this.previewTimer);
                    this.previewTimer = setTimeout(() => this.preview(), {{ \App\Events\MinutesLiveUpdated::PREVIEW_DELAY }});
                };
                ['input', 'change'].forEach((type) => document.addEventListener(type, this.onInput));
            @endif
        },
        destroy() {
            clearInterval(this.timer);
            clearTimeout(this.previewTimer);
            if (this.onInput) {
                ['input', 'change'].forEach((type) => document.removeEventListener(type, this.onInput));
            }
        },
        async preview() {
            const state = this.state();
            if (state === this.previewed) { return; }
            // One at a time: typed meanwhile, sent once this one is answered.
            if (this.previewing) { this.onInput(); return; }
            this.previewing = true;
            try {
                await $wire.previewMinutes();
                this.previewed = state;
            } catch (e) {
                // The save every two seconds still carries it — and says so if it fails too.
            } finally {
                this.previewing = false;
            }
        },
        async save() {
            if (this.saving) { return; }
            const state = this.state();
            // Nothing changed since the last save: nothing to send. After a failed one, again.
            if (state === this.saved && ! this.failed) { return; }
            this.saving = true;
            try {
                await Promise.race([
                    $wire.autosaveMinutes(),
                    new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), 15000)),
                ]);
                this.saved = state;
                this.failed = false;
                // As times are shown everywhere (Settings → General): 05:35:12 PM, or 17:35:12.
                this.savedAt = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: @js(\App\Support\Dates::twelveHours()) }).toUpperCase();
            } catch (e) {
                this.failed = true;
            } finally {
                this.saving = false;
            }
        },
    }"
    x-bind:style="failed ? { background: 'rgba(220, 38, 38, .08)', borderColor: 'rgba(220, 38, 38, .45)' } : {}"
    style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .6rem .9rem; border-radius: .6rem; background: rgba(37, 99, 235, .08); border: 1px solid rgba(37, 99, 235, .25); font-size: .875rem;"
>
    <span>
        {{ __('What you type is saved automatically and shown on the live view.') }}
        <span x-show="savedAt && ! failed" x-cloak style="opacity: .7;">— {{ __('Saved') }} <span x-text="savedAt"></span></span>
        <strong x-show="failed" x-cloak role="alert" style="color: rgb(220, 38, 38);">— {{ __('Not saved — check your connection') }}</strong>
    </span>
    <a href="{{ $url }}" target="_blank" rel="noopener" style="font-weight: 600; color: rgb(37, 99, 235); white-space: nowrap;">
        {{ __('Open the live view') }} ↗
    </a>
</div>
