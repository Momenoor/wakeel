<?php

/**
 * Files attached to records (fees, allocations, requests, matters …): see App\Services\Attachments.
 */
return [

    /*
     * The disk new files go to: private (storage/app/attachments), served only through the
     * attachment controller after a permission check. Files from before it was set up stay on
     * the public disk until they are moved (php artisan attachments:move).
     */
    'disk' => env('ATTACHMENTS_DISK', 'attachments'),

    /* The biggest file, in kilobytes. */
    'max_kb' => (int) env('ATTACHMENTS_MAX_KB', 20 * 1024),

    /*
     * What may be attached, by extension. An executable or a script never is: a file's name is
     * checked against this, and its content type against the extension.
     */
    'extensions' => [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp', 'tif', 'tiff',
        'zip', 'eml', 'msg', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'mov',
    ],
];
