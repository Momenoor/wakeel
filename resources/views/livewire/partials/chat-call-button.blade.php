{{-- A voice call to the other one of this conversation (filament.partials.chat-call); not a drag of the popup. --}}
<button
    type="button"
    x-on:pointerdown.stop
    x-on:click.stop="$dispatch('wk-call', { conversationId: {{ (int) $this->activeConversation->id }}, name: @js($user->display_name ?: $user->name), avatar: @js($this->avatarUrl($user)) })"
    title="{{ __('Voice call') }}"
    aria-label="{{ __('Voice call') }}"
    class="{{ $class }}"
    style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; flex-shrink: 0; border-radius: 9999px;"
>
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg>
</button>
