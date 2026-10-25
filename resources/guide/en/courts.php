<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Courts',
    'intro' => 'The list of courts that send you matters, with their phone, email and address. You choose a court on every matter, and it appears in letters and reports. Find it under "Settings → Master Data → Courts".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the courts',
            'description' => 'A table of all courts with their name, phone, email and address.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Court'],
            'steps' => [
                ['text' => 'In the side menu, go to Settings → Master Data → "Courts".', 'shot' => 'list-1'],
            ],
        ],
        [
            'id' => 'create',
            'title' => 'Add a court',
            'description' => 'Add a court once, then choose it on your matters.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Court'],
            'steps' => [
                ['text' => 'Click "New Court".', 'shot' => 'create-0'],
                ['text' => 'Enter the "Name" (required), "Phone", "Email address" and "Address".', 'shot' => 'create-2-filled'],
                ['text' => 'Click "Create". A "Created" message appears and the court\'s page opens, showing its matters.', 'shot' => 'create-3-done'],
            ],
        ],
        [
            'id' => 'edit',
            'title' => 'Edit or delete a court',
            'description' => 'To correct contact details, or delete a court that is not in use.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Court', 'Delete:Court'],
            'steps' => [
                ['text' => 'Click "Edit" on the court\'s row, change what you need, then click "Save changes". To delete, use the "Delete" button at the top of the page.', 'shot' => 'edit-1'],
            ],
            'tips' => ['Do not delete a court that has matters; edit its details instead.'],
        ],
        [
            'id' => 'view',
            'title' => 'View a court and its matters',
            'description' => 'The court\'s page shows its details and a table of its matters (its workload).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Court', 'View:CourtMattersRelation'],
            'steps' => [
                ['text' => 'Click the court\'s name or the eye icon.', 'shot' => 'view-1'],
            ],
        ],
    ],
];
