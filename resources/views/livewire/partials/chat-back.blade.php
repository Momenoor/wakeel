{{--
    Back to the conversation list: the popup's header, and the Chat page's on a phone (one at a time there).
    $class: the button's colours; $ring: the dot's ring, the header's colour behind it; $narrowOnly: shown below
    the width the page shows both side by side (the dock store's: 64rem).
--}}
<button type="button" wire:click="backToList" @if ($narrowOnly ?? false) x-show="! $store.dock?.wide" @endif class="relative flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full transition {{ $class }}" aria-label="{{ __('Back') }}">
    {{-- Points back toward the start side: left in English, right in Arabic. --}}
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" @if (__('filament-panels::layout.direction') === 'rtl') style="transform: scaleX(-1)" @endif>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
    </svg>
    {{-- A new message in another conversation while this one is open. --}}
    @if ($this->unreadElsewhereCount > 0)
        <span class="absolute -end-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-danger-500 ring-2 {{ $ring }}"></span>
    @endif
</button>
