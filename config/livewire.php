<?php

/*
 * Only what differs from Livewire's own config (vendor/livewire/livewire/
 * config/livewire.php) — the rest comes from there.
 */
return [

    /*
     * The rich editors send their text as a document tree: a table (table →
     * row → cell → paragraph → text) or a list inside a list goes well past
     * Livewire's 10 levels — "Property path [data.body.content.5.content.0
     * …] exceeds the maximum nesting depth". A long letter with its tables
     * can also pass 1 MB.
     *
     * The whole "payload" group is given: Laravel merges the package's
     * config one level deep only.
     */
    'payload' => [
        'max_size' => 8 * 1024 * 1024,
        'max_nesting_depth' => 40,
        'max_calls' => 50,
        'max_components' => 200,
    ],

    /*
     * Files picked in a form go up first to a temporary folder — at most
     * 12 MB each by Livewire's default, short of the 20 MB the chat and
     * the letters' attachments allow. The whole group is given, as above.
     */
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:20480'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
