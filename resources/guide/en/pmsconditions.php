<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Conditions templates and print templates',
    'intro' => 'The system prints the lease on the official government form. "Conditions Templates" keep the special conditions the office adds to a lease (the general conditions are fixed legal text and are not managed here). "Print Templates" hold the images of the lease, invoice and receipt forms and set where each field goes on them. Find them under "Conditions Templates" and "Print Templates".',
    'actions' => [
        [
            'id' => 'conditions',
            'title' => 'Special conditions templates',
            'description' => 'A template is a set of clauses (in Arabic and English) attached to a lease according to the property\'s emirate and the lease format (Sharjah Commercial, Sharjah Residential, Dubai EJARI). It is suggested for you when you create a lease on units of a property in that emirate.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:ConditionTemplate', 'Create:ConditionTemplate', 'Update:ConditionTemplate', 'Delete:ConditionTemplate', 'View:ConditionTemplateItemsRelation'],
            'steps' => [
                ['text' => 'In "Conditions Templates", click "New Conditions Template".', 'shot' => 'list-1'],
                ['text' => 'Enter the "Name", choose the "Emirate" and the "Contract Format", then click "Create".', 'shot' => 'create-1'],
                ['text' => 'Open the template with "Edit". In the "Clauses" table, click "Add Clause" and enter the "Arabic Text", "English Text" and "Order". Repeat for each clause.', 'shot' => 'edit-1'],
            ],
        ],
        [
            'id' => 'print',
            'title' => 'Print templates (leases, invoices and receipts)',
            'description' => 'Each lease format or document type (lease contract, tax invoice, receivable receipt) has a template made of pages. Each page has a background image (the actual form), and you place the data fields on it with a visual designer. You can link a template to an owner group so that the invoices for its properties print on the group\'s letterhead.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:LeasePrintTemplate', 'Create:LeasePrintTemplate', 'Update:LeasePrintTemplate', 'Delete:LeasePrintTemplate', 'View:LeasePrintTemplatePagesRelation'],
            'steps' => [
                ['text' => 'In "Print Templates", click "New Print Template". Enter the "Name" and choose the "Document Type" and the "Contract Format" (and an "Owner Group" if needed).', 'shot' => 'pmsprint/create-1'],
                ['text' => 'Open the template with "Edit". Under "Page Templates", click "New Page Template": set the "Page Number", upload the background image of the form, then click "Create".', 'shot' => 'pmsprint/page-add'],
                ['text' => 'The pages table shows each page number and the number of "Fields Placed" on it. The "Edit" icon changes the page number or its background image.', 'shot' => 'pmsprint/page-builder'],
                ['text' => 'Click the page number to open the designer. Drag the fields (tenant name, rent, dates…) to their places on the form image, then save.'],
            ],
            'tips' => ['Print a test lease after each change to check that the fields line up with the form.'],
        ],
    ],
];
