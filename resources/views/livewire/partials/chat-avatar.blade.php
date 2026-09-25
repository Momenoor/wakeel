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
        {{-- Same HTML whatever the status — Alpine colours it from the live
             presence channel when Pusher is set up, else from $wire's
             last_seen_at list — so Livewire re-renders never reset it. --}}
        <span
            x-data="{ get on() { const ids = $store.chatOnline?.ids; return (ids ?? $wire.onlineUserIds).includes({{ $user->id }}); } }"
            class="absolute bottom-0 end-0 block h-2.5 w-2.5 rounded-full ring-2 ring-white dark:ring-gray-900"
            :class="on ? 'bg-green-500 shadow-[0_0_6px_2px_rgba(34,197,94,0.55)]' : 'bg-red-500 shadow-[0_0_6px_2px_rgba(239,68,68,0.5)]'"
            :title="on ? @js(__('Online')) : @js(__('Offline'))"
        ></span>
    @endif
</span>
