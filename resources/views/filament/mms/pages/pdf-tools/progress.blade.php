{{-- What a PDF tool is doing (its Alpine component's `busy`, `status` and `percent`). --}}
<section x-show="busy" x-cloak class="{{ $card }}">
    <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
        <x-filament::loading-indicator class="size-5 text-primary-600" />
        <span x-text="status"></span>
    </div>
    <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" x-show="percent !== null">
        <div class="h-full rounded-full bg-primary-600 transition-[width]" x-bind:style="{ width: (percent ?? 0) + '%' }"></div>
    </div>
</section>
