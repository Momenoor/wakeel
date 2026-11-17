{{--
    How to install the app where the browser makes no offer of its own (filament.partials.app-install): an
    iPhone or iPad (Share, then Add to Home Screen), or any other browser (its menu).
--}}
<div
    x-data="{
        open: false,
        ios: /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
    }"
    x-on:wakeel-install-help.window="open = true"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    x-transition.opacity
    style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0, 0, 0, .5);"
>
    <div x-on:click.outside="open = false" class="bg-white text-gray-950 dark:bg-gray-900 dark:text-white" style="width: min(400px, 100%); border-radius: 18px; padding: 22px 20px; box-shadow: 0 20px 50px rgba(0,0,0,.3);">
        <h2 style="font-size: 1.05rem; font-weight: 600; margin: 0 0 12px;">{{ __('Install the app') }}</h2>

        <template x-if="ios">
            <ol style="margin: 0; padding-inline-start: 1.2rem; display: flex; flex-direction: column; gap: 8px; font-size: .9rem; line-height: 1.6;">
                <li>{{ __('Open the system in Safari.') }}</li>
                <li>{{ __('Tap the Share button (the square with an arrow pointing up).') }}</li>
                <li>{{ __('Choose "Add to Home Screen", then "Add".') }}</li>
            </ol>
        </template>
        <template x-if="! ios">
            <ol style="margin: 0; padding-inline-start: 1.2rem; display: flex; flex-direction: column; gap: 8px; font-size: .9rem; line-height: 1.6;">
                <li>{{ __('Open the browser\'s menu (⋮ or ⋯ at the top or bottom of the window).') }}</li>
                <li>{{ __('Choose "Install app", "Apps → Install this site as an app" or "Add to home screen".') }}</li>
                <li>{{ __('Not there? The app is installed on this device already, or this browser cannot install apps: use Chrome or Edge.') }}</li>
            </ol>
        </template>

        <p class="text-gray-500 dark:text-gray-400" style="margin: 14px 0 0; font-size: .8rem; line-height: 1.6;">{{ __('The app opens in its own window with its own icon, keeps you signed in and shows notifications. It is always up to date: nothing to install again after an update.') }}</p>

        <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
            <button type="button" x-on:click="open = false" class="bg-primary-600 hover:bg-primary-500" style="padding: 8px 18px; border-radius: 9999px; color: #fff; font-size: .85rem; font-weight: 600;">{{ __('OK') }}</button>
        </div>
    </div>
</div>
