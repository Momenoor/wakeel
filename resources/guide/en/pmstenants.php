<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Tenants',
    'intro' => 'The register of tenants (people and companies) the office contracts with. You choose the parties to leases and quotations from it. Find it under "Tenants" in the property panel.',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the tenants',
            'description' => 'A table of the tenants and their details, with the "Add Tenant" and "Import Tenants" (from a file) buttons, and the edit and delete icons.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Tenant', 'View:Tenant'],
            'steps' => [['text' => 'In the property panel, go to "Tenants".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Add a tenant',
            'description' => 'The form has: full name, phone and email; "Tenant Type" (Person or Company); "Identification Type" (Emirates ID, passport, trade license…) and its number, the unified number and nationality; and an emergency contact.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Tenant', 'Update:Tenant', 'Delete:Tenant'],
            'steps' => [
                ['text' => 'Click "Add Tenant".', 'shot' => 'create-1'],
                ['text' => 'Enter the "Full Name" (required), phone and email. Choose the "Tenant Type" and the "Identification Type", enter its number, then click "Create".', 'shot' => 'create-2-filled'],
            ],
            'tips' => ['You can also add a tenant while creating a lease, in the "Tenants" step, without leaving the wizard.'],
        ],
    ],
];
