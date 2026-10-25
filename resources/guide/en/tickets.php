<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Flight tickets and end-of-service gratuity',
    'intro' => 'This section covers two of an employee\'s financial entitlements: the yearly flight ticket, and the end-of-service gratuity, which is calculated under Federal Decree-Law No. 33 of 2021, Article 51, and accrued every month.',
    'actions' => [
        [
            'id' => 'tickets',
            'title' => 'Flight Tickets',
            'description' => 'The system creates a yearly ticket for eligible employees. It is paid either through the payroll run (so it shows on the payslip) or outside it. The table shows the employee, the year, the amount and the payment status.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:FlightTickets'],
            'steps' => [
                ['text' => 'In the side menu, go to "Human Resources" → "Flight Tickets".', 'shot' => 'list-1'],
                ['text' => 'To record a ticket paid outside the payroll, click "Mark as paid", enter the "Paid On" date and a "Note", then click "Submit".', 'shot' => 'markpaid-1'],
            ],
            'tips' => ['Unpaid tickets are shown in the payroll run, so you can choose which to pay with the salary.'],
        ],
        [
            'id' => 'eosg-voucher',
            'title' => 'Yearly end-of-service gratuity closing voucher',
            'description' => 'At the end of the financial year, a journal voucher is generated with the total gratuity accrued (the liability) for all employees, with a statement explaining the balance movements, to be posted to the accounts.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:EndOfServiceGratuityClosingVoucher', 'Generate:EosgClosingVoucher'],
            'steps' => [
                ['text' => 'In the side menu, go to "Financial" → "EOSG Closing Voucher".', 'shot' => 'eosg/page-1'],
                ['text' => 'Enter the "Closing Year" and click "Generate" to create the voucher and show the journal entry.'],
            ],
            'tips' => ['The calculation rules (days per year, the cap, the minimum service) are in "Financial Settings → Payroll". They are legal figures and change only when the law changes.'],
        ],
    ],
];
