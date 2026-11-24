<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    // Pusher with any key missing throws while the app boots — every page,
    // not just chat — and a fresh install copies BROADCAST_CONNECTION=pusher
    // from .env.example before anyone has keys. Falls back to 'log' until
    // all three are set.
    'default' => env('BROADCAST_CONNECTION') === 'pusher'
        && ! (env('PUSHER_APP_ID') && env('PUSHER_APP_KEY') && env('PUSHER_APP_SECRET'))
            ? 'log'
            : env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            // The server hands each event to its own Reverb straight, inside the machine (reverb:start listens on
            // 127.0.0.1:8080) — not through the site's address, which the browsers use (REVERB_HOST, config/filament.php):
            // that went out to the web server and back, and where it listens on the public address only it was
            // refused ("Failed to connect to … port 443"), and nothing was live. REVERB_PUBLISH_* for a Reverb elsewhere.
            'options' => [
                'host' => env('REVERB_PUBLISH_HOST', '127.0.0.1'),
                'port' => env('REVERB_PUBLISH_PORT', env('REVERB_SERVER_PORT', 8080)),
                'scheme' => env('REVERB_PUBLISH_SCHEME', 'http'),
                'useTLS' => env('REVERB_PUBLISH_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
