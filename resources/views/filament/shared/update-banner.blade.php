{{-- Rendered at the top of every panel page (see the panel providers). --}}
@php
    $updateState = \App\Support\AppUpdate::canManage() ? app(\App\Services\Updater\Updater::class)->state() : null;
    $updateAvailable = \App\Support\AppUpdate::canManage() && \App\Support\AppUpdate::available();
    $updatesUrl = \App\Filament\Shared\Pages\SystemUpdates::getUrl();
@endphp

@if ($updateState !== null && ! request()->routeIs('filament.*.pages.system-updates'))
    <div class="mb-4 rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400">
        {{ __('An update to version :version has not finished — the site is in maintenance mode.', ['version' => $updateState['version']]) }}
        <a href="{{ $updatesUrl }}" class="font-semibold underline">{{ __('Open System Updates') }}</a>
    </div>
@elseif ($updateAvailable && ! request()->routeIs('filament.*.pages.system-updates'))
    <div class="mb-4 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 ring-1 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400">
        {{ __('Version :version is available — you are on :current.', ['version' => \App\Support\AppUpdate::latestVersion(), 'current' => \App\Support\AppUpdate::currentVersion()]) }}
        <a href="{{ $updatesUrl }}" class="font-semibold underline">{{ __('View update') }}</a>
    </div>
@endif
