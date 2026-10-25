<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Employees',
    'intro' => 'Each employee\'s profile holds their employment details, official documents and when they expire, bank details for salaries, salary structure (basic and allowances), end-of-service gratuity, leave and flight ticket. An employee starts as a "Party" with the "Employee" role in Parties. Find this section under "Human Resources → Employees".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the employees',
            'description' => 'A table with the employee number, name, designation and date of joining, with the "New Employee" and "Import Employees" (from a file) buttons and the edit and delete icons.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:EmployeeProfile', 'View:EmployeeProfile'],
            'steps' => [
                ['text' => 'In the side menu, go to "Human Resources" → "Employees".', 'shot' => 'list-1'],
                ['text' => 'Use the "Import Employees" button to upload a list of employees from a file in one go.', 'shot' => 'list-import'],
            ],
        ],
        [
            'id' => 'create',
            'title' => 'Add an employee',
            'description' => 'You choose the party (with the employee role), then fill in the other sections.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:EmployeeProfile'],
            'steps' => [
                ['text' => 'Click "New Employee". Under "Employment", choose the "Employee" (from the parties) and enter the "Employee Number". Choose the "Designation" (from the "Designations" list; you can add a new one from the field) and the "Nationality" (from a fixed list in Arabic and English). Enter the "Name in Arabic" (for Arabic letters and certificates; English ones use the main name), the "Name as WPS From", the "Date of Joining" (required) and the "Date of Leaving" if there is one.', 'shot' => 'create-1'],
                ['text' => 'Fill in the "Identity Documents" (passport, Emirates ID, labour card and MOHRE personal number) with their expiry dates, the "Residency & Visa", and the "Banking & WPS" section (bank, account number, IBAN and routing code, and whether the employee is included in the salary authorization form).'],
                ['text' => 'In the "Gratuity & Leave" section, set whether gratuity applies, the opening leave and gratuity balances, and the gratuity amount already paid and when. Under "Flight Ticket", set whether the employee is entitled to a yearly ticket and its amount. Then click "Create".'],
            ],
            'tips' => ['The expiry dates of the passport, Emirates ID, residency and labour card are watched, so you are alerted before they expire.', 'A letter or certificate writes the employee\'s name, designation and nationality in the letter\'s language ({{employee.name}} …). To ask for one language, add _ar or _en.'],
        ],
        [
            'id' => 'edit-salary',
            'title' => 'Edit an employee and their salary structure',
            'description' => 'Below the edit form are tables linked to the employee: "Salary Structure" (each component: basic, housing, transport, utilities, car, other, with an amount and an effective date), "Leave Balance" and "Flight Tickets". A salary change is recorded as a new component with an effective date, so the payslips of earlier months stay correct.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:EmployeeProfile', 'View:EmployeeSalaryComponentsRelation', 'View:EmployeeLeaveBalanceRelation', 'View:EmployeeFlightTicketsRelation'],
            'steps' => [
                ['text' => 'Click "Edit" on the employee\'s row, change the details, then click "Save changes". "Delete" is at the top of the page.', 'shot' => 'edit-1'],
                ['text' => 'Scroll down to "Salary Structure" and click "Add / Revise Component" to record a new salary or allowance with its effective date.', 'shot' => 'edit-2-salary'],
            ],
        ],
        [
            'id' => 'designations',
            'title' => 'Designations',
            'description' => 'The office\'s list of designations. Each has a "Name in Arabic" and a "Name in English", so a letter or certificate writes it in its own language. The "Designation" on an employee\'s profile is chosen from this list.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:HrListItem', 'Create:HrListItem', 'Update:HrListItem', 'Delete:HrListItem'],
            'steps' => [
                ['text' => 'In the side menu, go to "Human Resources" → "Designations". Click "New List entry" and enter both names, or use "Edit" and "Delete" on a designation\'s row.', 'shot' => 'designations-1'],
            ],
        ],
    ],
];
