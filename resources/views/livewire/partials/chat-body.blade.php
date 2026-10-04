@php
    $other = $showingThread && ! $this->activeConversation->is_group ? $this->otherParticipant($this->activeConversation) : null;
    $group = $showingThread && $this->activeConversation->is_group ? $this->activeConversation : null;
@endphp

<div class="flex items-center gap-3 bg-gradient-to-r from-primary-600 to-primary-500 px-4 py-3 text-white">
    @if ($showingThread && ($other || $group))
        <button type="button" wire:click="backToList" class="relative flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-white/90 transition hover:bg-white/15" aria-label="{{ __('Back') }}">
            {{-- Points back toward the start side: left in English, right in Arabic. --}}
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" @if (__('filament-panels::layout.direction') === 'rtl') style="transform: scaleX(-1)" @endif>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            {{-- A new message in another conversation while this one is open. --}}
            @if ($this->unreadElsewhereCount > 0)
                <span class="absolute -end-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-danger-500 ring-2 ring-primary-600"></span>
            @endif
        </button>
        @if ($group)
            @include('livewire.partials.chat-group-avatar', ['conversation' => $group, 'size' => 36])
            <span class="min-w-0 flex-1 truncate font-semibold">{{ $group->name }}</span>
            <button type="button" wire:click="toggleMembers" title="{{ __('Members') }}" aria-label="{{ __('Members') }}" style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; flex-shrink: 0; border-radius: 9999px;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </button>
        @else
            @include('livewire.partials.chat-avatar', ['user' => $other, 'size' => 36])
            <span class="flex min-w-0 flex-1 flex-col" style="line-height: 1.2;">
                <span class="truncate font-semibold">{{ $other->display_name ?: $other->name }}</span>
                {{-- Online, or when last seen — live, as on the Chat page (chat-thread.blade.php). --}}
                <span
                    data-chat-status="{{ $other->id }}"
                    data-online="{{ __('Online') }}"
                    data-offline="{{ $other->last_seen_at ? __('Last seen :time', ['time' => $other->last_seen_at->diffForHumans()]) : __('Offline') }}"
                    class="truncate text-xs text-white/80"
                ></span>
            </span>
        @endif
    @else
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5 flex-shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
        </svg>
        <span class="min-w-0 flex-1 truncate font-semibold">{{ __('Messages') }}</span>
    @endif

    <button type="button" x-on:click="toggle(false)" class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-white/90 transition hover:bg-white/15" aria-label="{{ __('Close') }}">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
    </button>
</div>

@if ($showingThread)
    @include('livewire.partials.chat-thread', ['isPopup' => true])
@else
    @include('livewire.partials.chat-sidebar')
@endif
