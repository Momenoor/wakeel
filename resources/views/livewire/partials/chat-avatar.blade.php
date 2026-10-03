@php
    $pixels = $size ?? 40;
    $showStatus = $showStatus ?? true;
@endphp

<span class="relative inline-flex flex-shrink-0" style="width: {{ $pixels }}px; height: {{ $pixels }}px;">
    <img
        src="{{ $this->avatarUrl($user) }}"
        width="{{ $pixels }}" height="{{ $pixels }}"
        class="h-full w-full rounded-full object-cover ring-2 ring-white dark:ring-gray-900"
        alt=""
    />
    @if ($showStatus)
        {{-- Red (offline) as rendered; turned green by the online-status
             style in chat-widget.blade.php. Nothing here for a re-render to
             undo — bound classes were wiped by each refresh, and the dot
             went blank. --}}
        <span
            data-chat-dot="{{ $user->id }}"
            class="absolute bottom-0 end-0 block h-2.5 w-2.5 rounded-full ring-2 ring-white dark:ring-gray-900"
            style="background: #ef4444; box-shadow: 0 0 6px 2px rgba(239, 68, 68, .5);"
            title="{{ $user->last_seen_at ? __('Last seen :time', ['time' => $user->last_seen_at->diffForHumans()]) : __('Offline') }}"
        ></span>
    @endif
</span>
