<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | By uncommenting the Laravel Echo configuration, you may connect Filament
    | to any Pusher-compatible websockets server.
    |
    | This will allow your users to receive real-time notifications.
    |
    */

    // The panels never load resources/js/app.js — Filament creates
    // window.Echo itself from this array, which is what the chat widget's
    // echo-private listener needs. Read from .env at runtime, so switching
    // BROADCAST_CONNECTION on a server needs no frontend rebuild. Only the
    // public key goes to the browser, never the secret.
    'broadcasting' => [

        'echo' => match (env('BROADCAST_CONNECTION')) {
            'pusher' => env('PUSHER_APP_ID') && env('PUSHER_APP_KEY') && env('PUSHER_APP_SECRET') ? [
                'broadcaster' => 'pusher',
                'key' => env('PUSHER_APP_KEY'),
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'forceTLS' => true,
                // Absolute, so a subfolder install (/wakeel) authorizes
                // against its own route rather than the domain root's.
                'authEndpoint' => rtrim((string) env('APP_URL'), '/').'/broadcasting/auth',
            ] : null,
            'reverb' => env('REVERB_APP_KEY') ? [
                'broadcaster' => 'reverb',
                'key' => env('REVERB_APP_KEY'),
                'wsHost' => env('REVERB_HOST'),
                'wsPort' => env('REVERB_PORT', 80),
                'wssPort' => env('REVERB_PORT', 443),
                'forceTLS' => env('REVERB_SCHEME', 'https') === 'https',
                'enabledTransports' => ['ws', 'wss'],
                'authEndpoint' => rtrim((string) env('APP_URL'), '/').'/broadcasting/auth',
            ] : null,
            default => null,
        },

    ],

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | This is the storage disk Filament will use to store files. You may use
    | any of the disks defined in the `config/filesystems.php`.
    |
    */

    // Every FileUpload/ImageColumn/ImageEntry this app authors already calls
    // ->disk('public') itself, so this default only governs components that
    // don't — like the vendor avatar field — which is why it must resolve to
    // the same disk uploads are actually written to. Tied to the app-wide
    // FILESYSTEM_DISK before, it silently followed that to 'local' (private,
    // no public URL), so avatar uploads saved fine but never displayed.
    'default_filesystem_disk' => 'public',

    /*
    |--------------------------------------------------------------------------
    | Assets Path
    |--------------------------------------------------------------------------
    |
    | This is the directory where Filament's assets will be published to. It
    | is relative to the `public` directory of your Laravel application.
    |
    | After changing the path, you should run `php artisan filament:assets`.
    |
    */

    'assets_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Cache Path
    |--------------------------------------------------------------------------
    |
    | This is the directory that Filament will use to store cache files that
    | are used to optimize the registration of components.
    |
    | After changing the path, you should run `php artisan filament:cache-components`.
    |
    */

    'cache_path' => base_path('bootstrap/cache/filament'),

    /*
    |--------------------------------------------------------------------------
    | Livewire Loading Delay
    |--------------------------------------------------------------------------
    |
    | This sets the delay before loading indicators appear.
    |
    | Setting this to 'none' makes indicators appear immediately, which can be
    | desirable for high-latency connections. Setting it to 'default' applies
    | Livewire's standard 200ms delay.
    |
    */

    'livewire_loading_delay' => 'default',

    /*
    |--------------------------------------------------------------------------
    | File Generation
    |--------------------------------------------------------------------------
    |
    | Artisan commands that generate files can be configured here by setting
    | configuration flags that will impact their location or content.
    |
    | Often, this is useful to preserve file generation behavior from a
    | previous version of Filament, to ensure consistency between older and
    | newer generated files. These flags are often documented in the upgrade
    | guide for the version of Filament you are upgrading to.
    |
    */

    'file_generation' => [
        'flags' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Route Prefix
    |--------------------------------------------------------------------------
    |
    | This is the prefix used for the system routes that Filament registers,
    | such as the routes for downloading exports and failed import rows.
    |
    */

    'system_route_prefix' => 'filament',

];
