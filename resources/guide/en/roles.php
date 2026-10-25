<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Roles and permissions',
    'intro' => 'A role is a set of permissions given to everyone who holds it: which pages, tables, tabs and widgets they see, what they can do (view, create, edit, delete, restore, export…), and each special action (approve, submit a report…). Roles are managed with the "Shield" tool. The Super admin (super-admin) passes every check and needs no permissions. Find this section under "Administration → Roles".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the roles',
            'description' => 'The table shows the existing roles, how many permissions each has and how many users hold it, with the edit and delete icons.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Role', 'View:Role'],
            'steps' => [['text' => 'In the side menu, go to "Administration" → "Roles".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Create a role and give it permissions',
            'description' => 'Permissions are named "Action:Resource", for example Create:Matter. The page splits them into four tabs: Resources (the system\'s tables and forms), Pages, Widgets, and Custom Permissions (special actions such as approving requests and submitting reports).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Role', 'Update:Role'],
            'steps' => [
                ['text' => 'Click "New Role" and enter the "Name" (for example: Accountant). Leave the "Guard Name" as it is (web).', 'shot' => 'create-1'],
                ['text' => 'To give every permission, turn on "Select All". Otherwise, open a tab and tick what you want.'],
                ['text' => 'The "Resources" tab: each resource (matters, parties, courts…) has its permissions: view any, view, create, update, delete, restore, force delete, replicate, reorder…', 'shot' => 'tab-resources'],
                ['text' => 'The "Pages" tab: who can open each page (reports, settings, maintenance…).', 'shot' => 'tab-pages'],
                ['text' => 'The "Widgets" tab: who sees each widget on the dashboard.', 'shot' => 'tab-widgets'],
                ['text' => 'The "Custom Permissions" tab: specific actions, such as approving requests, submitting the final report, collecting fees, and the tabs of the matter page.', 'shot' => 'tab-custom'],
                ['text' => 'After naming the role, click "Create" to save it. Then assign it to users on the user page (the "Roles" field).', 'shot' => 'create-2-named'],
            ],
            'tips' => [
                'Start with a narrow role and add permissions step by step. Test it with "Impersonate User".',
                'The "Access Control Maintenance" page in Settings shows permissions that are left over or cannot be reached.',
            ],
        ],
    ],
];
