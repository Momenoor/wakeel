<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Companies',
    'intro' => 'The office\'s companies, each with its trade licence and establishment card, the copies of both, and an alarm before either expires. Find this section under "Human Resources → Companies".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the companies and their expiring documents',
            'description' => 'A table with each company\'s name (in English, the Arabic under it), its "Licence number" and who issued it, the "Licence expiry", the "Establishment card number" and its expiry, and how many documents are attached. The "Expiring Documents" column is the alarm: a badge for the trade licence or establishment card that has expired (red) or expires within 60 days (amber), with its date and the days left. The table is sorted by the licence expiry, the soonest first.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Company', 'View:Company'],
            'steps' => [
                ['text' => 'In the side menu, go to "Human Resources" → "Companies". The red number beside "Companies" counts the companies with a document expired or expiring within 60 days.'],
                ['text' => 'Use the "Has Expiring Documents" filter to list only those companies.'],
            ],
            'tips' => ['Whoever may change companies is told on the bell (and by email, as they choose under "Notification preferences") 60, 30 and 7 days before the trade licence or establishment card expires, and on the day itself.'],
        ],
        [
            'id' => 'create',
            'title' => 'Add a company',
            'description' => 'Its name, its trade licence and its establishment card, then its documents.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Company', 'Update:Company'],
            'steps' => [
                ['text' => 'Click "New Company". Enter the "Name in English" (required) and the "Name in Arabic".'],
                ['text' => 'Under "Trade licence", enter the "Licence number", who it was "Issued by" (the Department of Economy, a free zone …), and its "Start date" and "Expiry date".'],
                ['text' => 'Under "Establishment card", enter its number, "Issue date" and "Expiry date". Add any "Notes", then click "Create".'],
                ['text' => 'The company opens for editing: under "Documents", click "Attach files" and attach the copies of the trade licence, the establishment card and anything else, each with its "Category".'],
            ],
            'tips' => ['When a licence or card is renewed, change its dates on the company and attach the new copy: the alarm goes quiet once the new expiry is more than 60 days away.'],
        ],
        [
            'id' => 'employees',
            'title' => 'The employees a company sponsors',
            'description' => 'The "Sponsored employees" table on the company\'s page lists the employees whose visas are under its establishment card, with their visa number, residency expiry and documents expiring. The companies table counts them.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:EmployeeProfile', 'Update:EmployeeProfile'],
            'steps' => [
                ['text' => 'Click "Edit" on the company\'s row and scroll down to "Sponsored employees". The employee\'s name opens their page.'],
                ['text' => 'Click "Add employees" and choose them (one sponsored by another company moves to this one), or click the remove icon on a row to take an employee off this company.'],
            ],
        ],
        [
            'id' => 'payroll',
            'title' => 'A company\'s payroll runs',
            'description' => 'Each company has its own monthly payroll run for the employees it sponsors. Its "Payroll" section holds what its Salary Authorization Form and journal voucher carry besides its name and trade licence number: the "WPS Employer ID", the "GL Number" (its account at the exchange house), the "Exchange house" and its "Bank Transfer Fee" (empty: the one in Financial Settings → Payroll).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Company', 'ViewAny:PayrollRun', 'Create:PayrollRun'],
            'steps' => [
                ['text' => 'On the company\'s page, fill in the "Payroll" section and click "Save changes".'],
                ['text' => 'Scroll down to "Payroll Runs": the company\'s runs, with their totals, journal voucher and Salary Authorization Form. Click "New Payroll Run" to start this month\'s with the company already chosen.'],
            ],
        ],
    ],
];
