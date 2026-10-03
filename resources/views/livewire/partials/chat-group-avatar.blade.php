{{-- A group's picture: its name's initials. --}}
@php($pixels = $size ?? 40)
<span class="relative inline-flex flex-shrink-0" style="width: {{ $pixels }}px; height: {{ $pixels }}px;">
    <img src="{{ $this->groupAvatarUrl($conversation) }}" width="{{ $pixels }}" height="{{ $pixels }}" class="h-full w-full rounded-full object-cover ring-2 ring-white dark:ring-gray-900" alt="" />
</span>
