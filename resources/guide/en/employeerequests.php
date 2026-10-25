<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Employee requests',
    'intro' => 'Here employees file their requests: a "Loan", "Petty cash" or a "Salary advance" (an amount paid back from their salary over some months), a "Ticket advance" (the year\'s flight ticket paid now), a "Certificate" from a template (salary certificate, no-objection letter and so on), or "Vacation" (this opens the leave request form itself). Requests are decided the same way as leave: by email (with approve and reject links) and the bell for those who receive leave requests, or in the system. The employee is told the decision. An approved money request is paid by the accountant, and only then does it become an advance that the payslip deducts. An approved certificate is issued at once on the office paper with a reference number. It is sent with the decision and can be downloaded from the request. Find this under "Human Resources → Employee requests", with "Leave Requests" below it.',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the requests',
            'description' => 'The table shows "Employee" (for those who see everyone\'s requests), "Request", "Details" (the amount, its months and first month, or the ticket and its year, or the certificate and who it is for), "Status", "Paid on" ("Not paid yet" for an approved request not yet paid), "Decided by" and "Requested on". The filters are "Request", "Status" and "Approved, not paid yet". Each employee sees only their own requests. Only a role given "ViewAll:EmployeeRequest" (the general manager and the accountant, to start with) sees everyone\'s requests and can file for someone else. The number next to the menu item is for those who decide or pay: what is waiting for a decision and what is waiting to be paid.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:EmployeeRequest', 'View:EmployeeRequest', 'ViewAll:EmployeeRequest (to see everyone\'s requests)'],
            'steps' => [
                ['text' => 'In the side menu, go to "Human Resources" → "Employee requests". Above the table are "New request" and "New leave request". Each row has "View", "Approve" and "Reject" (for a pending request) and "Mark paid" (for an approved request not yet paid).', 'shot' => 'list-1'],
            ],
            'tips' => ['Find a request with the global search by the employee\'s name, employee number, phone, email, ID or passport, or by the request number (#12).'],
        ],
        [
            'id' => 'create-money',
            'title' => 'Request a loan, petty cash or a salary advance',
            'description' => 'The employee enters the amount, the number of months to pay it back over, and the month whose salary the deduction starts from. The instalments show at once as they type. A salary advance cannot be more than the salary for its months (the "Monthly salary" shows below it).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:EmployeeRequest'],
            'steps' => [
                ['text' => 'Click "New request". "Employee" is you (someone who sees everyone\'s requests can choose another employee). Choose the type under "Request".', 'shot' => 'create-0'],
                ['text' => 'Under "Amount and repayment", enter the "Amount" and the "Months", choose "First deduction from the salary of", and check the "Instalments" below. Enter the "Reason", then click "Create".', 'shot' => 'create-1-loan'],
            ],
            'tips' => ['You can edit or delete a request only while it is pending. Once it is decided, it stays as decided.', 'The user account must be linked to an employee record to file requests.'],
        ],
        [
            'id' => 'create-ticket',
            'title' => 'Request the year\'s ticket in advance',
            'description' => 'An employee who is entitled to a yearly ticket asks for the value of a year\'s ticket now. The "Amount" is filled with that year\'s ticket value when you choose the year. It is paid directly, so the payroll run does not pay it again.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:EmployeeRequest'],
            'steps' => [
                ['text' => 'Choose "Ticket advance", then the "Ticket of the year" (this year or next), check the "Amount", then click "Create".', 'shot' => 'create-3-ticket'],
            ],
            'tips' => ['If the employee has no ticket due for that year (not entitled, or already paid), the system refuses the request.'],
        ],
        [
            'id' => 'create-certificate',
            'title' => 'Request a certificate',
            'description' => 'The employee chooses the certificate from the "Employee certificates" templates, who it is addressed to and its purpose. Certificate templates are managed under "Settings → Templates → Letter Templates" with the "Employee certificates" category. They have the employee\'s placeholders (name, job title, joining date, passport, salary and its total in words, and so on).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:EmployeeRequest'],
            'steps' => [
                ['text' => 'Choose "Certificate", then the "Certificate" (the template). Enter "Addressed to" (for example: To whom it may concern) and the "Purpose", then click "Create".', 'shot' => 'create-2-certificate'],
            ],
        ],
        [
            'id' => 'decide',
            'title' => 'Approve or reject a request',
            'description' => 'A new request arrives in one email to those who receive leave requests (Settings → Communication → Letters & emails), with the approve and reject links, and in the bell. In the system, "Approve" opens the request as it was filed, to approve it as it is or after changing the amount, the months, the first month or the certificate, with a "Note". "Reject" asks for the "Reason". The employee is told the decision. The accountant is told about an approved money request so they can pay it, and an approved certificate is issued at once.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Approve:EmployeeRequest'],
            'steps' => [
                ['text' => 'Click "Approve" on the request\'s row: "Approve … request" as asked, or after changing it, then click "Submit".', 'shot' => 'approve-modal'],
                ['text' => 'Or click "Reject", enter the "Reason", then click "Submit".', 'shot' => 'reject-modal'],
            ],
            'tips' => ['If the certificate could not be issued on approval, issue it from the request\'s page with the "Issue the certificate" button (or "Issue again" for a certificate whose template changed).'],
        ],
        [
            'id' => 'pay',
            'title' => 'Record the payment of a money request',
            'description' => 'After approval, the accountant hands over the amount, then records "Mark paid" with its date and a note (cash, transfer reference and so on). Only then is the advance created in "Financial → Loans & Petty Cash", and its instalments are deducted from the salary from the approved month. An advance ticket is recorded as paid and is not paid again with the salary. The employee is told of the payment.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Pay:EmployeeRequest'],
            'steps' => [
                ['text' => 'Click "Mark paid" on the request\'s row or at the top of its page: "Paid to …?" with a summary of the request. Choose the "Paid on" date and enter a "Note", then click "Mark paid".', 'shot' => 'paid-modal'],
                ['text' => 'The request\'s page ("View") shows the "Request" as approved, the "Decision" (decided by, on, note) and the "Disbursement" (paid on, marked paid by, the advance it created). For a certificate, it shows the "Reference number" and the download.', 'shot' => 'view-1'],
            ],
        ],
    ],
];
