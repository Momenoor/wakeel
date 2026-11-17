{{-- Emojis for the message (filament.partials.chat-emoji-script): above the message box, open until closed. --}}
<div x-data="wkEmoji" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" style="flex-shrink: 0;">
    <button
        type="button"
        x-on:click="toggle()"
        title="{{ __('Emoji') }}"
        aria-label="{{ __('Emoji') }}"
        x-bind:style="open ? { color: 'var(--primary-600)' } : {}"
        style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 9999px; color: rgb(107 114 128);"
    >
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/></svg>
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        class="wk-emoji-panel border border-gray-950/10 bg-white shadow-xl dark:border-white/10 dark:bg-gray-900"
        style="position: absolute; bottom: calc(100% + 6px); inset-inline: 8px; z-index: 30; display: flex; flex-direction: column; height: 270px; border-radius: 16px; overflow: hidden;"
    >
        <div class="border-b border-gray-100 dark:border-white/10" style="display: flex; gap: 2px; padding: 4px 6px; overflow-x: auto;">
            <button type="button" x-show="recent.length" x-on:click="tab = -1" title="{{ __('Recently used') }}" x-bind:class="tab === -1 ? 'bg-gray-100 dark:bg-white/10' : ''" style="width: 34px; height: 30px; flex-shrink: 0; border-radius: 8px; font-size: 1rem;">🕘</button>
            <template x-for="(group, index) in groups" x-bind:key="index">
                <button type="button" x-on:click="tab = index" x-bind:title="group.label" x-bind:class="tab === index ? 'bg-gray-100 dark:bg-white/10' : ''" style="width: 34px; height: 30px; flex-shrink: 0; border-radius: 8px; font-size: 1.05rem;" x-text="group.icon"></button>
            </template>
        </div>
        <p class="text-gray-500 dark:text-gray-400" style="padding: 6px 10px 2px; font-size: .7rem; font-weight: 600;" x-text="title"></p>
        <div style="flex: 1; min-height: 0; overflow-y: auto; padding: 2px 6px 8px; display: grid; grid-template-columns: repeat(auto-fill, minmax(36px, 1fr)); align-content: start;">
            <template x-for="emoji in shown" x-bind:key="emoji">
                <button type="button" x-on:click="pick(emoji)" class="hover:bg-gray-100 dark:hover:bg-white/10" style="height: 36px; border-radius: 8px; font-size: 1.35rem; line-height: 1;" x-text="emoji"></button>
            </template>
        </div>
    </div>
</div>
