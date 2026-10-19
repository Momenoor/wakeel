<?php

declare(strict_types=1);

/*
| Laravel merges this file over the package's own en/filament-shield.php, so
| only what is overridden needs to appear here.
*/

return [
    // The sidebar group holding Settings, Users, Roles and the activity log —
    // the package calls it "Filament Shield".
    'nav.group' => 'Administration',

    'resource_permission_prefixes_labels' => [
        // Signing PDFs (App\Support\ScreenPermissions).
        'use_sign_pdf' => 'Sign and stamp PDFs',
        'use_shared_signature_stamp' => 'Use the office\'s signatures and stamps',
        'manage_shared_signature_stamp' => 'Manage the office\'s signatures and stamps',
    ],
];
