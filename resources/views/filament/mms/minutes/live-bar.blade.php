{{--
    At the top of the Record the meeting page: what's typed is saved every few
    seconds (RecordMinutes::autosaveMinutes, which doesn't redraw
    the page) so the live view the attendees watch keeps up.
--}}
<div
    x-data="{
        saving: false,
        savedAt: null,
        timer: null,
        init() {
            this.timer = setInterval(() => this.save(), 3000);
        },
        destroy() {
            clearInterval(this.timer);
        },
        async save() {
            if (this.saving) { return; }
            this.saving = true;
            try {
                await $wire.autosaveMinutes();
                this.savedAt = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            } finally {
                this.saving = false;
            }
        },
    }"
    style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .6rem .9rem; border-radius: .6rem; background: rgba(37, 99, 235, .08); border: 1px solid rgba(37, 99, 235, .25); font-size: .875rem;"
>
    <span>
        {{ __('What you type is saved automatically and shown on the live view.') }}
        <span x-show="savedAt" x-cloak style="opacity: .7;">— {{ __('Saved') }} <span x-text="savedAt"></span></span>
    </span>
    <a href="{{ $url }}" target="_blank" rel="noopener" style="font-weight: 600; color: rgb(37, 99, 235); white-space: nowrap;">
        {{ __('Open the live view') }} ↗
    </a>
</div>
