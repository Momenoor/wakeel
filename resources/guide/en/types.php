<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Matter Types',
    'intro' => 'The matter type (engineering, accounting, medical or real estate expertise, and so on) decides how the incentive is calculated, what the parties are called in letters (plaintiff, landlord, appellant), which extra fields the matter form asks for, and which letter templates are available. Find it under "Settings → Master Data → Matter Types".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the types',
            'description' => 'The table shows each type\'s name, its incentive configuration, whether it is active, its incentive options and its number of matters.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Type'],
            'steps' => [
                ['text' => 'In the side menu, go to Settings → Master Data → "Matter Types".', 'shot' => 'list-1'],
            ],
        ],
        [
            'id' => 'create',
            'title' => 'Add a matter type',
            'description' => 'The form has: the name, the incentive configuration (from the incentive calculations), the incentive trigger type (final report date or fee entry date), active, "Allow Current Status Import" (counts the incentive on matters whose report is not delivered yet, as long as their fees are collected), "Exclude from Incentive Count", and "Party capacities".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Type'],
            'steps' => [
                ['text' => 'Click "New Matter Type".', 'shot' => 'create-0'],
                ['text' => 'Fill in the name and choose the incentive configuration and the trigger type. In the "Party capacities" section, write what each side is called in this type, such as "Plaintiff / Defendant" or "Landlord / Tenant", or pick from "Common names" to fill it in for you. These names appear on the matter page, in letters and in minutes.', 'shot' => 'create-1'],
            ],
            'tips' => [
                'Turning off "Active" hides the type from the new matter form without deleting its old matters.',
                'A type with phases can give each phase folders of its own: in "OneDrive folder structure", "Each phase\'s folders" → "Add a phase\'s folders", choose the phase and write its subfolders one per line. They are made inside a folder named after the phase, instead of the lines that start with {{matter.phase}}; the other lines are made for every phase. They are made when a matter is made, when a supplementary matter is made, and when a matter\'s phase is changed by editing it.',
            ],
        ],
        [
            'id' => 'view',
            'title' => 'View a type and its custom fields',
            'description' => 'The type\'s page shows its settings and the "Field Definitions" table: extra fields shown on the form of every matter of this type (text, list, date or toggle). They can be required.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Type', 'View:TypeFieldDefinitionsRelation'],
            'steps' => [
                ['text' => 'Click the type\'s name or the eye icon. Add or edit custom fields in the table at the bottom.', 'shot' => 'view-1'],
            ],
            'tips' => ['A custom field with "Show beside the matter\'s number" turned on appears next to the matter\'s number wherever matters are picked or searched for (the "With the number" column in the table), so you can tell similar matters apart.'],
        ],
        [
            'id' => 'bulk',
            'title' => 'Bulk actions on types',
            'description' => 'Select one or more types, then open the "Bulk actions" menu to apply one action to all of them.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Type', 'Delete:Type'],
            'steps' => [
                ['text' => 'Tick the types, then click "Bulk actions" at the top of the table. You will see: "Assign Incentive Config", "Assign letter templates", "Set party capacities" and "Delete selected".', 'shot' => 'bulk-1'],
                ['text' => '"Assign Incentive Config": choose the configuration used to calculate the incentives of all the selected types.', 'shot' => 'bulk-3-config'],
                ['text' => '"Assign letter templates": choose the templates and how to apply them (add them to the types\' letters, replace the letters with them, or remove them).', 'shot' => 'bulk-4-letters'],
                ['text' => '"Set party capacities": pick from the common names, or write the names for the plaintiff, the defendant and the implicated litigant for all the selected types.', 'shot' => 'bulk-2-capacities'],
            ],
        ],
    ],
];
