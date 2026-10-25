<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Expertise Areas',
    'intro' => 'The list you pick the "Expertise Area" from when you register an expert as a party (accounting, engineering, medicine, and so on). Each area has an English name and an Arabic name, an order, and can be hidden. Find it under "Settings → Master Data → Expertise Areas".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the expertise areas',
            'description' => 'The table shows both names, the number of experts in each area, and whether it is active.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:ExpertiseArea'],
            'steps' => [['text' => 'In the side menu, go to Settings → Master Data → "Expertise Areas".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Add an area',
            'description' => 'You add an area from a small window without leaving the list.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:ExpertiseArea'],
            'steps' => [
                ['text' => 'Click "New Expertise Area". The window opens.', 'shot' => 'create-1'],
                ['text' => 'Enter the "Name (English)" (required) and the "Name (Arabic)", and set the "Order". Leave "Offered on the party form" on so the area appears on the expert form. Then click "Create".'],
            ],
        ],
        [
            'id' => 'edit',
            'title' => 'Edit an area',
            'description' => 'To change the name or the order, or to hide the area from the form while keeping it for current experts.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:ExpertiseArea'],
            'steps' => [['text' => 'Click the pencil icon on the area\'s row, make your changes, then click "Save changes".', 'shot' => 'edit-1']],
        ],
        [
            'id' => 'delete',
            'title' => 'Delete an area',
            'description' => 'You can delete an area that has no experts. If it has experts, the system stops you, so hide it instead.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Delete:ExpertiseArea'],
            'steps' => [['text' => 'Click the trash icon and confirm with "Delete".', 'shot' => 'delete-1']],
        ],
    ],
];
