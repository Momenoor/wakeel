<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

$everyone = 'Every user who can open the property panel';

return [
    'title' => 'Property reports',
    'intro' => 'Nine reports, found under "Reports" in the property panel. Each one starts with the same "Filters" box: "Owner group" and "Building" (the property) narrow the report to one owner group or one property, followed by the filters of that report. The "Print" and "Excel" buttons are at the top of the page. Each active filter shows as a tag you can remove, and "Reset" clears them all.',
    'actions' => [
        [
            'id' => 'rent-roll',
            'title' => 'Rent roll',
            'description' => 'A list of the property units: the property, unit, type, tenant, lease start and end dates, rent, deposit and days left. Vacant units are listed too, so it is the main place to see who occupies what, and for how much.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'In "Reports", choose "Rent roll". The filters: owner group, building, status, type, and "Search".', 'shot' => 'rent-roll-1'],
                ['text' => 'Scroll to the end of the table to see the totals.', 'shot' => 'rent-roll-2'],
            ],
        ],
        [
            'id' => 'occupancy',
            'title' => 'Occupancy & vacancy',
            'description' => 'For each building: the number of units, how many are "Occupied", "Vacant", "Under maintenance" and "Reserved", the occupancy rate, and the annual rent of its current leases.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Choose "Occupancy & vacancy", and pick an owner group or a building if you want.', 'shot' => 'occupancy-1'],
                ['text' => 'Read the table and its totals.', 'shot' => 'occupancy-2'],
            ],
        ],
        [
            'id' => 'expiry',
            'title' => 'Lease expiry',
            'description' => 'The leases about to end, sorted by end date, with filters for renewal and dispute status, so you can start negotiating before they end.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Choose "Lease expiry". The filters: owner group, building, "Ending", "Renewal" and "Dispute".', 'shot' => 'expiry-1'],
                ['text' => 'Open a lease from the table to evaluate its renewal.', 'shot' => 'expiry-2'],
            ],
        ],
        [
            'id' => 'arrears',
            'title' => 'Arrears aging',
            'description' => 'Who owes rent past its due date, and for how long: for each lease, the unpaid balance of its overdue instalments split into 0–30, 31–60, 61–90 and more than 90 days, with the penalties, the oldest due date and the total overdue.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Choose "Arrears aging". The filters: owner group, building, and "Oldest overdue" (more than 30/60/90 days). If there are none, "No arrears" is shown.', 'shot' => 'arrears-1'],
            ],
        ],
        [
            'id' => 'collections',
            'title' => 'Collections',
            'description' => 'Rent due against rent collected, month by month (by due date): the number of instalments, due, collected, outstanding, the collection rate and the penalties. Security deposits are not rent, so they are not counted here.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Choose "Collections", and set the "Due date", the owner group and the building.', 'shot' => 'collections-1'],
                ['text' => 'Read the table and the totals.', 'shot' => 'collections-2'],
            ],
        ],
        [
            'id' => 'payments',
            'title' => 'Payments received',
            'description' => 'Every payment received in a period, to match against the bank deposits: the payment date, tenant, units, instalment due date, payment method, bank, reference and amount.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Choose "Payments received". The filters: "Paid on", "Method", owner group, building, and "Search".', 'shot' => 'payments-1'],
            ],
        ],
        [
            'id' => 'deposits',
            'title' => 'Security deposits',
            'description' => 'The security deposits held against leases: the agreed amount and how much of it was collected, and, after the lease ends, what is due back to the tenant ("Refund") and what was carried over to the renewal.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Choose "Security deposits". The filters: "Refund due only", "Lease status", owner group and building.', 'shot' => 'deposits-1'],
            ],
        ],
        [
            'id' => 'owner-statement',
            'title' => 'Owner statements',
            'description' => 'For each owner group, over a period: the rent due and collected, the VAT on it, the outstanding amount and the deposits held. Each row has a detailed "Statement PDF" to send to the owner.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Choose "Owner statements", and set the "Period" and the owner group or building.', 'shot' => 'owner-statement-1'],
                ['text' => 'Click "Statement PDF" on the group\'s row to download its statement.', 'shot' => 'owner-statement-2'],
            ],
        ],
        [
            'id' => 'vat',
            'title' => 'VAT report',
            'description' => 'The output tax on rent, for your VAT return: every instalment with VAT in the period (by date of supply, or by due date if no supply date is recorded), with its tax invoice number, the landlord\'s and tenant\'s tax registration numbers, the net, the rate, the VAT and the total.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [['text' => 'Choose "VAT report" and set the "Date of supply". Then check the table and export it for your return.', 'shot' => 'vat-1']],
        ],
    ],
];
