<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Leases',
    'intro' => 'The heart of the property system: a lease links a property\'s units to one or more tenants, with a term, a rent and instalments. Its life cycle: draft → submitted for attestation (Ejari/Sharjawai) → active → renewed or terminated. When it becomes active, its instalment schedule is created. You then record payments, a bounced cheque is charged a penalty, and you print the lease, the tax invoices and the receivable receipts. Find it under "Leases" in the property panel.',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the leases',
            'description' => 'The table shows the leases with their status (Quotation, Draft, Pending Attestation, Active, Renewed, Terminated, Expired), their parties and their value.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Lease', 'View:Lease'],
            'steps' => [['text' => 'In the property panel, choose "Leases". The "New Lease" button is above the table.', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Create a lease (a 6-step wizard)',
            'description' => 'You create a lease step by step: property → units → tenants → contract details → instalments and payments → special conditions. Each step is marked complete once you fill it in. There is another way too: convert an accepted quotation straight into a lease.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Lease'],
            'steps' => [
                ['text' => 'Click "New Lease". Step 1, "Property": choose the property from the cards, then click "Next".', 'shot' => 'wizard-1-property'],
                ['text' => 'Step 2, "Units": choose the "Unit" from the property\'s units. Click "Add Unit" if the lease covers more than one unit.', 'shot' => 'wizard-2b-unit-picked'],
                ['text' => 'Step 3, "Tenants": choose the "Primary Tenant" (required). Add co-tenants or guarantors with "Add Tenant" if needed. You can also create a new tenant from the same field.', 'shot' => 'wizard-3b-tenant-picked'],
                ['text' => 'Step 4, "Contract Details": "Dates" (start, end, issue date and grace period), "Financials" (total base rent, the calculated annual rent, the deposit, and whether there are multiple rent amounts), "Contract Identification" (the contract number and the contract type that suits the units\' classification), "Occupancy & Use", and "Power of Attorney" if the lease is signed under a power of attorney.', 'shot' => 'wizard-4-terms'],
                ['text' => 'Step 5, "Installments & Payments": set the "Number of Instalments", the "Payment Method" (Post-Dated Cheque, Direct Debit (UAEDD), Bank Transfer, Credit Card, Cash), the "Bank for all instalments" and the "First cheque / reference number". You can add instalments by hand with "Add Installment".', 'shot' => 'wizard-5-installments'],
                ['text' => 'Step 6, "Special Conditions": choose the "Conditions Template" (suggested for you from the property\'s emirate), then click "Create" to save the lease.'],
            ],
            'tips' => ['A lease is not complete without a primary tenant and at least one unit.'],
        ],
        [
            'id' => 'view',
            'title' => 'View a lease and its instalments',
            'description' => 'The lease page shows: the lease details, "Attestation" (the system and serial number), "Tenants", "Units", and the "Instalments" table with the due date, net, VAT, total, paid, balance, payment method, cheque and status. The action buttons at the top depend on the lease status.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Lease', 'View:LeaseInstallmentsRelation'],
            'steps' => [
                ['text' => 'Open the lease by clicking "View". The buttons of an active lease: Evaluate Renewal, Renew, Terminate, the print buttons, Print Tax Invoices and Print Receivable Receipt.', 'shot' => 'view-active'],
                ['text' => 'A draft lease shows "Edit", "Submit for Attestation" and "Generate Schedule" (it creates the instalments for you from the number of instalments and the rent).', 'shot' => 'view-draft'],
            ],
        ],
        [
            'id' => 'attest',
            'title' => 'Submit a lease for attestation and register it',
            'description' => 'A lease becomes "Active" only after it is officially attested (Ejari in Dubai, Sharjawai in Sharjah…). You submit the draft for attestation, then register the "Attestation / Ejari Serial Number", the "Attestation System" and the "Title Deed Number", and the lease becomes active.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Lease'],
            'steps' => [
                ['text' => 'On the draft lease\'s page, click "Submit for Attestation", then "Confirm". The message "Lease submitted for attestation." appears.', 'shot' => 'attest-modal'],
                ['text' => 'Once the attestation is issued, click "Register Attestation". Choose the "Attestation System" and enter the serial number and the title deed number. The message "Lease attested and marked active." appears.'],
            ],
        ],
        [
            'id' => 'payments',
            'title' => 'Record an instalment payment and mark it bounced',
            'description' => 'When you receive money from the tenant, record a payment on the instalment (part or full), with its method and reference. If a cheque bounces, mark the instalment "Bounced": the amount received for it is cancelled and the "Bounced Cheque Penalty (AED)" is added (its value comes from the property settings).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Lease'],
            'steps' => [
                ['text' => 'In the instalments table, click "Record Payment" on the instalment. Enter the "Amount (AED)", "Payment Method", "Transaction / Cheque Reference", "Bank Name" and "Paid Date", then click "Submit".', 'shot' => 'pay-modal'],
                ['text' => '"View Payments" lists all the payments on the instalment.', 'shot' => 'payments-modal'],
                ['text' => 'Click "Mark Bounced", then "Confirm". This cancels what was received for the instalment and adds the penalty.', 'shot' => 'bounce-modal'],
            ],
        ],
        [
            'id' => 'renew',
            'title' => 'Evaluate a renewal and renew a lease',
            'description' => '"Evaluate Renewal" compares your current rent with the market average and with the cap allowed by the Real Estate Regulatory Agency (RERA), and suggests the highest rent allowed. Then "Renew" creates a new lease with the new dates and rent.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Lease', 'Create:Lease'],
            'steps' => [
                ['text' => 'Click "Evaluate Renewal". Enter the "Target Renewal Date" and the "Market Average Rent (AED)", then click "Submit" to see the proposal and the highest rent allowed.', 'shot' => 'renew-eval'],
                ['text' => 'Click "Renew". Enter the "New Start Date", "New End Date" and "New Total Base Rent (AED)", then click "Submit". The message "Lease renewed." appears.', 'shot' => 'renew-modal'],
            ],
        ],
        [
            'id' => 'terminate',
            'title' => 'Terminate a lease',
            'description' => 'Ends the lease before its end date. Its details and payments are kept for reference.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Lease'],
            'steps' => [['text' => 'Click "Terminate", then "Confirm". The message "Lease terminated." appears.', 'shot' => 'terminate-modal']],
        ],
        [
            'id' => 'print',
            'title' => 'Print the lease, the tax invoices and the receivable receipt',
            'description' => 'On the lease page: "Print (…)" in the lease format that suits the emirate (Sharjah Commercial, Sharjah Residential, Dubai) on the approved print template, "Print Tax Invoices" for the lease\'s instalments, and "Print Receivable Receipt".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Lease'],
            'steps' => [['text' => 'Click the print button you need at the top of the lease page. The document opens in a new window, ready to print or save as PDF.', 'shot' => 'view-active']],
            'tips' => ['You set where each lease field sits on the government form in "Print Templates".'],
        ],
    ],
];
