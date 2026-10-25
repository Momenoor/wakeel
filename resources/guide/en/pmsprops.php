<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Properties and units',
    'intro' => 'A property is the building or tower as shown on its government title deed. Under it are its units (flats, shops and offices), which are what you lease. Each property has its owners and their ownership shares, or an owner group and its bank account. Find it under "Properties" in the property panel.',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the properties',
            'description' => 'A table of the properties and their details, with the "New Property" and "Import Properties" (from a file) buttons.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Property', 'View:Property'],
            'steps' => [
                ['text' => 'In the property panel, go to "Properties". Click "New Property" to add one.', 'shot' => 'list-1'],
                ['text' => '"Import Properties" uploads a list of properties from a file in one go.', 'shot' => 'list-import'],
            ],
        ],
        [
            'id' => 'create',
            'title' => 'Add a property',
            'description' => 'The form has: "Property" (name, address, year built, emirate, owner group and bank account), "Government Property Details" (municipality, district, area, title deed number and date, government number, property type and number), and "Owners" (the owner and the "Ownership %").',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Property'],
            'steps' => [
                ['text' => 'Click "New Property". Enter the "Property Name" (required) and the address, and choose the "Emirate" (it sets the lease format and the conditions templates).', 'shot' => 'create-1'],
                ['text' => 'Fill in the government title deed details. Then, under "Owners", choose the owner and set the "Ownership %" (you can add more than one owner). Or choose an "Owner Group" for a property run by heirs or a company. Then click "Create".', 'shot' => 'create-2-filled'],
            ],
        ],
        [
            'id' => 'units',
            'title' => 'Manage a property\'s units',
            'description' => 'You add units on the property\'s edit page, in the "Units" table: unit number, floor, type, property classification (Residential/Commercial/Industrial/Mixed Use), annual rent, area, number of rooms and status (Vacant…).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Property', 'View:PropertyUnitsRelation'],
            'steps' => [
                ['text' => 'Click "Edit" on the property\'s row. The "Units" table appears below the form.', 'shot' => 'edit-1'],
                ['text' => 'Click "Add Unit". Enter the "Unit Number", "Unit Type", "Property Classification" and "Rental Rate (AED/year)", then click "Create".', 'shot' => 'unit-add'],
                ['text' => 'To enter many units at once, click "Import Units". Click "Download example CSV file", fill it in, upload it and click "Import".', 'shot' => 'unit-import'],
            ],
        ],
    ],
];
