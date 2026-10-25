<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Owners and owner groups',
    'intro' => 'An owner is a person or company that owns a property or a share in one. Their details appear in owner statements and in their collections. An "Owner Group" is a single body that runs a jointly owned property (heirs, for example), with one or more bank accounts. Find them under "Owners" and "Owner Groups".',
    'actions' => [
        [
            'id' => 'owners',
            'title' => 'Add and manage owners',
            'description' => 'The form has: "Owner" (full name, phone, email, Emirates ID or passport number, unified number, nationality, tax registration number and owner group) and "Banking" (bank name, account number and IBAN).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:OwnerProfile', 'Create:OwnerProfile', 'Update:OwnerProfile', 'Delete:OwnerProfile'],
            'steps' => [
                ['text' => 'In "Owners", click "Add Owner". Use "Import Owners" to upload a list in one go.', 'shot' => 'list-1'],
                ['text' => 'Enter the "Full Name" (required) and the other details. Choose an "Owner Group" if the owner is an heir in a group. Fill in "Banking", then click "Create".', 'shot' => 'create-1'],
            ],
        ],
        [
            'id' => 'groups',
            'title' => 'Owner groups and their accounts',
            'description' => 'A group has a name, phone, email and tax registration number, its "Bank Accounts" (one of them the default), and a "Properties" table of the properties it runs. Each property is linked to one of the group\'s bank accounts, which receives its rent.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:OwnerGroup', 'Create:OwnerGroup', 'Update:OwnerGroup', 'Delete:OwnerGroup', 'View:OwnerGroupPropertiesRelation'],
            'steps' => [
                ['text' => 'In "Owner Groups", click "New Owner Group" and enter the "Group Name" and its details.', 'shot' => 'groups-list'],
                ['text' => 'Open the group with "Edit". Add its "Bank Accounts". Then, in the "Properties" table, click "Add Property" to assign a property to the group.', 'shot' => 'group-edit'],
                ['text' => 'In the "Add Property" window, choose the "Property" and the "Bank Account" that receives its rent, then click "Submit". To change the account later, use "Change Bank Account". To unlink the property, use "Remove from Group".', 'shot' => 'group-add-prop'],
            ],
        ],
    ],
];
