<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Loans & Petty Cash',
    'intro' => 'Here you record employees\' loans, petty cash and salary advances (the type: "Staff Loan", "Petty Cash" or "Salary advance"). The amount is split into instalments over the payroll months automatically, and each instalment is deducted from the payslip when the run is paid. A loan is also created here automatically from an approved "Employee request" when its payment is recorded (see the "Employee requests" section). Find this section under "Financial → Loans & Petty Cash".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View loans and petty cash',
            'description' => 'The table shows the employee, type, amount, instalments and status, with a "View" icon.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:EmployeeLoan', 'View:EmployeeLoan'],
            'steps' => [['text' => 'In the side menu, go to "Financial" → "Loans & Petty Cash".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Record a loan or petty cash',
            'description' => 'Choose the employee and the type (loan or petty cash), and enter the amount, the number of months and the month of the first instalment. The system makes the instalment schedule automatically. It rounds every instalment after the first down to a multiple of the "Instalment Rounding Step (AED)" (in the Financial Settings) and adds the difference to the first instalment.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:EmployeeLoan'],
            'steps' => [
                ['text' => 'Click "New Advance".', 'shot' => 'create-1'],
                ['text' => 'Choose the "Employee" and the "Type", and enter the "Amount (AED)", the "Months", the "First Instalment" (the starting month) and any "Notes", then click "Create". The loan\'s page opens with its instalment schedule.'],
            ],
        ],
        [
            'id' => 'view-approve',
            'title' => 'View and approve a loan',
            'description' => 'The page shows the "Advance" (employee, type, amount and what is outstanding), the "Recovery" (months and first instalment), the "Approval" (who approved it and when), and the "Instalments" table with the status of each instalment ("Outstanding" or "Deducted"). A loan that is not yet approved shows an approve button to anyone with the permission.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:EmployeeLoan', 'Approve:EmployeeLoan', 'View:EmployeeLoanInstallmentsRelation'],
            'steps' => [['text' => 'Click "View" on the loan\'s row to see its instalments and who approved it.', 'shot' => 'view-1']],
            'tips' => ['Instalments are deducted automatically from the payslip for their month when the payroll run is generated.'],
        ],
    ],
];
