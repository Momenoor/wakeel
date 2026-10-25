<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Parties',
    'intro' => 'The record of the people and organisations the office deals with: the parties to cases (plaintiff and defendant), legal representatives, appointed experts and their assistants, employees, and also tenants and owners in the property system. You add a party once and use them in every matter and letter. Find it in the side menu → "Parties".',
    'actions' => [

        [
            'id' => 'list',
            'title' => 'View the parties and their tabs',
            'description' => 'The table shows the name, phone, fax, email and role. The tabs split the parties by kind: All, Parties, Representatives, Experts, Employees.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Party', 'View:PartiesAllTab', 'View:PartiesPartiesTab', 'View:PartiesRepresentativesTab', 'View:PartiesExpertsTab', 'View:PartiesEmployeesTab'],
            'steps' => [
                ['text' => 'In the side menu, choose "Parties".', 'shot' => 'list-1'],
                ['text' => 'The tabs above the table sort the parties. Each user sees only the tabs their role allows.', 'shot' => 'list-tabs'],
                ['text' => 'Click the "Experts" tab to see only the experts and their assistants.', 'shot' => 'list-tab-experts'],
            ],
        ],

        [
            'id' => 'filter',
            'title' => 'Search and filter the parties',
            'description' => 'Search by name, phone or email, or narrow the list with the filters: "Role" and "Sub Role" (for experts: certified, assistant, external, external assistant).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Party'],
            'steps' => [
                ['text' => 'Type part of the name or number in the "Search" box.', 'shot' => 'list-1'],
                ['text' => 'Click the filter icon, choose the role or sub role, then click "Apply filters".', 'shot' => 'list-filters'],
            ],
        ],

        [
            'id' => 'create',
            'title' => 'Add a new party',
            'description' => 'You create a party with a name, contact details and a role. For an expert, extra fields appear for the expert type and the expertise area. For an assistant, OneDrive account fields appear so their matter folders can be created.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Party'],
            'steps' => [
                ['text' => 'In the parties list, click "New Party".', 'shot' => 'create-0'],
                ['text' => 'Type the "Name" (it must not be used already). In "Phone", type the number, then press Enter or a comma to turn it into a tag. You can add more than one number and more than one email address.', 'shot' => 'create-2-filled'],
                ['text' => 'Choose the "Role" (Party, Representative, Expert, Employee, Tenant, Owner). You can choose more than one. If you choose "Expert", set its type: "Certified Expert", "Assistant Expert", "External Expert" or "External Assistant", and set the "Expertise Area".'],
                ['text' => 'Click "Create". A "Created" message appears and the party\'s page opens.', 'shot' => 'create-3-done'],
            ],
            'tips' => [
                'The role is required. If you see "The Role field is required.", make sure at least one option is ticked.',
                'You can create a new party straight from the matter form without leaving it (the + icon next to the field).',
                'Expertise areas are managed under "Settings → Master Data → Expertise Areas".',
            ],
        ],

        [
            'id' => 'view',
            'title' => 'View a party and their matters',
            'description' => 'The party\'s page shows who they are, their roles and contact details, and below that a table of every matter their name appears in.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Party', 'View:PartyMattersRelation'],
            'steps' => [
                ['text' => 'Click the eye icon on the party\'s row, or their name, to see their details and their matters table. Open any matter from the table by clicking "View".', 'shot' => 'view-1'],
            ],
        ],

        [
            'id' => 'edit-delete',
            'title' => 'Edit or delete a party',
            'description' => 'You can edit any field of a party\'s details, and delete a party you no longer need. To delete several at once, select them in the table.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Party', 'Delete:Party'],
            'steps' => [
                ['text' => 'Click the "Edit" icon on the party\'s row. The form opens with its current values, and "View" and "Delete" buttons at the top.', 'shot' => 'edit-1'],
                ['text' => 'Change what you need, then click "Save changes". Or click "Delete" and confirm in the window.', 'shot' => 'delete-1'],
            ],
            'tips' => ['Be careful when deleting a party: they are linked to earlier matters and letters.'],
        ],
    ],
];
