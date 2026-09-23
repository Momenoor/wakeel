<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-check-badge" icon-color="success" :heading="__('The update worked')">
        <p>{{ __('This page was added in version 1.0.8. If you can see it, the one-click update installed the new code.') }}</p>

        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Installed version') }}</dt>
                <dd class="text-lg font-semibold">{{ \App\Support\AppUpdate::currentVersion() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Last updated') }}</dt>
                <dd class="text-lg font-semibold">{{ \App\Models\Setting::get('last_updated_at') ?? '—' }}</dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
