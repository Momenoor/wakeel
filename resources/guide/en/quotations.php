<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Quotations',
    'intro' => 'A quotation is a rental offer to a prospective client before you sign a lease: the units, the offered rent, the term, the expected instalments and an estimate of the attestation fees. If the client accepts, you turn it into a lease with one click, with all its details. Find it under "Quotations".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the quotations',
            'description' => 'A table of the quotations and their status (Draft, Sent, Accepted, Rejected, Expired), with the "View" and "Edit" icons.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Quotation', 'View:Quotation'],
            'steps' => [['text' => 'In the property panel, go to "Quotations".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Create a quotation',
            'description' => 'Choose the prospect or tenant and the date the offer is valid until. Then choose the units and the rent for each, the proposed contract details, the number of instalments and the payment method. The "Expected Instalments" table is then filled in for you.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Quotation', 'Update:Quotation'],
            'steps' => [
                ['text' => 'Click "New Quotation", and choose the "Prospect / Tenant" and the "Valid Until" date.', 'shot' => 'create-1'],
                ['text' => 'Under "Units", choose the unit and enter the "Offered Rent (AED/year)". Under "Contract Details", set the start and end dates, the grace period, the contract type, the deposit, the number of instalments and the payment method. Then click "Create".'],
            ],
        ],
        [
            'id' => 'view-convert',
            'title' => 'View, print, send and convert a quotation to a lease',
            'description' => 'The quotation page shows its details, the units, the "Totals" (rent, VAT and estimated attestation fees) and the expected instalments. Its buttons are "Print" (a PDF for the client), "Send" and "Convert to Lease".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Quotation', 'Create:Lease'],
            'steps' => [
                ['text' => 'Open a draft quotation with "View". The "Send" button changes its status, and "Print" gives you a paper copy.', 'shot' => 'view-2'],
                ['text' => '"Convert to Lease" creates a new lease with the same units, dates and instalments. Review it and submit it for attestation.'],
            ],
            'tips' => ['The attestation fees on a quotation are an estimate taken from "PMS Settings". The actual fee is confirmed when the lease is signed.'],
        ],
    ],
];
