<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Payroll Runs',
    'intro' => 'A payroll run is the monthly payroll for all employees. The system makes a payslip for each employee from their salary components (basic and allowances), with the finalized incentive and flight tickets, and deducts unpaid leave and loan instalments. The run then goes through approval stages: Draft → HR review → Finance approval → Disbursed. It also produces the journal voucher and the salary transfer form (WPS). Find this section under "Financial → Payroll Runs".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the payroll runs',
            'description' => 'The table shows each run with its period, status and totals, and the "Journal Voucher", "Print Voucher" and "Print Salary Form" buttons.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:PayrollRun', 'View:PayrollRun'],
            'steps' => [['text' => 'In the side menu, go to "Financial" → "Payroll Runs".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Create a payroll run and generate the payslips',
            'description' => 'The run is created for the period (the month), and a payslip is made automatically for each employee with a salary in effect in that period.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:PayrollRun', 'Generate:PayrollRun'],
            'steps' => [
                ['text' => 'Click "New Payroll Run".', 'shot' => 'create-1'],
                ['text' => 'Set the "Period" (the month), and enter a "Name" and "Notes" if you like, then save. The run is created, and its payslips are generated if you have permission to generate them.', 'shot' => 'create-2-filled'],
            ],
            'tips' => ['Two runs cannot have the same period. If something changes (leave, a loan), return the run to "Draft" and generate it again.'],
        ],
        [
            'id' => 'review',
            'title' => 'Review the run and its payslips',
            'description' => 'The run\'s page has: the "Summary" (period, status, number of employees, gross, deductions, net payable and gratuity accrued), the "Approvals" (who approved and when), and the "Payslips" table with each employee: basic, allowances, incentive, flight ticket, gross, unpaid days and their deduction, loan and net.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:PayrollRun'],
            'steps' => [
                ['text' => 'Open the run by clicking "View". A run under review shows the "HR Approve" and "Return to Draft" buttons.', 'shot' => 'view-review'],
                ['text' => 'A disbursed run shows the completed approvals and the disbursement date, with no buttons.', 'shot' => 'view-disbursed'],
            ],
            'tips' => ['If a payslip is marked "Flagged for Review", read the note before approving.', 'The "Incentive deductions" column, and its line on the payslip: whatever an incentive calculation\'s deductions are not covered by its incentive (paid shares taken back, or a fixed deduction) is deducted once from the salary of the month in which the calculation is finalized. It stays when the payslip is edited.', 'Loan instalments (including a salary advance) are deducted from the month approved in their request, and a ticket paid in advance from "Employee requests" is not paid again with the salary.', 'The journal voucher groups the lines by account and type.'],
        ],
        [
            'id' => 'approvals',
            'title' => 'Approval and disbursement stages',
            'description' => 'Each stage has its own permission and button, so no one person handles the payroll alone: "Send to HR Review" (from draft) → "HR Approve" (checks the days) → "Finance Approve" → "Mark Disbursed". "Return to Draft" is there for corrections.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['HrApprove:PayrollRun', 'FinanceApprove:PayrollRun', 'Disburse:PayrollRun'],
            'steps' => [
                ['text' => 'Click "HR Approve", then "Confirm". The run moves on to Finance.', 'shot' => 'hr-approve-modal'],
                ['text' => 'To send the run back for changes, click "Return to Draft" and confirm.', 'shot' => 'return-modal'],
            ],
            'tips' => ['On "Mark Disbursed", flight tickets paid through the payroll are settled and loan instalments are recorded as repaid.'],
        ],
        [
            'id' => 'voucher',
            'title' => 'Journal voucher and salary transfer form',
            'description' => 'From the run you get the accounting "Journal Voucher" (salaries, deductions, gratuity and transfer charges) to print or export, and the "Salary Form" (WPS) that you upload to the exchange house.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewJournalVoucher:PayrollRun'],
            'steps' => [['text' => 'Click "Journal Voucher" to preview it, then "Print Voucher" or "Print Salary Form".', 'shot' => 'voucher-1']],
        ],
    ],
];
