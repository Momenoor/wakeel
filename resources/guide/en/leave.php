<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Leave',
    'intro' => 'Employees\' leave requests and the ledger of approved leave. When a request is approved, the absence is split across the leave types (annual, sick on full, half or no pay, unpaid…). It is taken off the balance for that type and counted in the salary, and the days are recorded in the leave ledger so the system sees them when it assigns matters. Find it under "Human Resources → Employee requests → Leave Requests" and "Leave Ledger". A leave request can also be made from "Employee requests" (the "New leave request" button or the "Vacation" option).',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the leave requests',
            'description' => 'The table shows the employee, the period, the number of days, the leave type asked for and the request\'s status ("Pending", "Approved", "Rejected"). Pending requests have "Approve", "Reject", "Edit" and "Delete" buttons. Each employee sees only their own requests. Only a role given the "ViewAll:LeaveRequest" permission sees everyone\'s (the super admin and the accountant to start with; change it in Roles). The approve permission on its own does not show other people\'s requests.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:LeaveRequest', 'View:LeaveRequest', 'ViewAll:LeaveRequest (to see everyone\'s requests)'],
            'steps' => [
                ['text' => 'In the side menu, go to "Human Resources" → "Employee requests" → "Leave Requests".', 'shot' => 'list-1'],
                ['text' => 'The buttons on each pending request: approve, reject, edit, delete.', 'shot' => 'list-actions'],
            ],
        ],
        [
            'id' => 'create',
            'title' => 'Make a leave request',
            'description' => 'The employee or HR makes the request with the period, the type asked for and the reason.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:LeaveRequest'],
            'steps' => [
                ['text' => 'Click "New Leave Request". Choose the "Employee", set "From" and "To" and the "Requested Leave Type", and enter the "Reason", then click "Create".', 'shot' => 'create-1'],
            ],
            'tips' => ['A new request arrives in one email to everyone chosen under "Settings → Communication → Letters & emails → Leave requests are sent to" (empty: everyone with the approve permission), each as they chose in their notifications, along with the bell. The email has approve and reject buttons that work without signing in.', 'An employee cannot make a request for someone else, unless they can see everyone\'s requests.', 'For annual leave, the email gives the days taken off the leave balance and the balance before and after.'],
        ],
        [
            'id' => 'approve',
            'title' => 'Approve a leave request',
            'description' => 'When you approve, you decide how the absence is split: a request can be divided into more than one type (for example 5 days annual, then 2 days unpaid). The system will not approve it if the annual days are more than the balance available.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Approve:LeaveRequest'],
            'steps' => [
                ['text' => 'Click "Approve" on the request\'s row. In the window, set the "Leave Type", "From", "To" and "Days" for each part, use "Add to Leave Breakdown" to add another type, and enter a "Reviewer Comment", then click "Approve".', 'shot' => 'approve-modal'],
            ],
            'tips' => ['The approved days are recorded in the leave ledger, and unpaid leave is deducted on the payslip.', 'The employee gets the decision in one email, with a copy to those who receive requests, however it was approved (in the system or from the email link) and whoever the request was for.', 'Sick leave approved as suggested (including a one-click approval from the email) is split across its bands: full pay, then half pay, then unpaid.'],
        ],
        [
            'id' => 'reject',
            'title' => 'Reject a leave request',
            'description' => 'A rejection needs a "Reason for Rejection", so the employee knows why.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Approve:LeaveRequest'],
            'steps' => [['text' => 'Click "Reject", enter the "Reason for Rejection", then click "Confirm".', 'shot' => 'reject-modal']],
        ],
        [
            'id' => 'party-leaves',
            'title' => 'Leave Ledger (recorded absence)',
            'description' => 'A list of the days parties (assistants above all) were absent, with the type and the reason. It is filled automatically from approved requests, and you can also add an absence by hand. The system uses it to count working days in the overdue matters report and when it assigns new matters.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:PartyLeave', 'Create:PartyLeave', 'Update:PartyLeave', 'Delete:PartyLeave'],
            'steps' => [
                ['text' => 'Go to "Human Resources" → "Leave Ledger".', 'shot' => 'pleaves-list'],
                ['text' => 'Click "New Leave / Vacation", enter the party, the period, the type, the pay factor and the reason, then click "Create".', 'shot' => 'pleaves-create'],
            ],
        ],
    ],
];
