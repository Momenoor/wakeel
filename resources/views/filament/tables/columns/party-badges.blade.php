{{--
    Renders a matter's parties or experts as "role badge + name", one per line.

    Replaces two sprintf() blocks that hand-authored Filament's internal fi-badge
    / fi-color classes into a raw HTML string. Those rendered correctly only
    because of the order Filament emits its own CSS, so any upstream reordering
    would have silently turned every badge grey. This uses the real component.

    Expected state: a list of ['label', 'index', 'name', 'color'] — and 'alwaysLabelled' to show the label on
    every screen (it is otherwise hidden on smaller and high-density ones, see .type-label).

    A long name is cut short (the whole of it on hover) and wraps within the column rather than widening the table.
--}}
@php
    $rows = $getState() ?? [];
    $longest = 40;
@endphp

<div class="flex flex-col gap-1 px-3 py-2">
    @forelse ($rows as $row)
        <div class="flex items-start gap-1.5 text-xs">
            <x-filament::badge :color="$row['color']" size="sm" class="shrink-0">
                <span @class(['type-label' => ! ($row['alwaysLabelled'] ?? false)])>{{ $row['label'] }}</span> #{{ $row['index'] }}
            </x-filament::badge>
            <span class="break-words" style="white-space: normal; flex: 1 1 0%; min-width: 9rem;" @if (mb_strlen($row['name']) > $longest) title="{{ $row['name'] }}" @endif>{{ \Illuminate\Support\Str::limit($row['name'], $longest) }}</span>
        </div>
    @empty
        <span class="text-xs text-gray-400 dark:text-gray-500">&mdash;</span>
    @endforelse
</div>
