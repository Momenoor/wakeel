{{-- What went wrong, for any of the PDF tools (its Alpine component's `error`). --}}
<div x-show="error" x-cloak class="flex items-start justify-between gap-2 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400">
    <span x-text="error"></span>
    <button type="button" class="font-semibold" x-on:click="error = null" aria-label="{{ __('Close') }}">&times;</button>
</div>
