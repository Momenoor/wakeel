<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

/** One report = one action. Every report page has the same anatomy, explained once in the intro. */
return [
    'title' => 'Reports',
    'intro' => 'Find the reports in the side menu under "Reports". A sub-menu lists every report. They all work the same way. At the top is the "Filters" box: make your choices, then click "Apply filters". Active filters show as small badges you can remove, and "Reset" clears them all. Below is the table, with its totals at the end, and a "Print" button in the corner. Each report has its own permission that decides who sees it, so users see only what their role allows.',
    'actions' => [

        [
            'id' => 'monthly',
            'title' => 'Matters Monthly Report',
            'description' => 'A monthly summary: how many new matters came in, how many initial and final reports were delivered, and the total fees, with a "Summary" row at the end of the table. Useful for following the workload and output month by month.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MattersMonthlyReport'],
            'steps' => [
                ['text' => 'Under "Reports", choose "Matters Monthly Report". Filters: year, assistant, court, matter type, collection status and distribution date.', 'shot' => 'monthly-1'],
                ['text' => 'Columns: month, new matters, initial reports, final reports and total fees. The totals row is at the end of the table.', 'shot' => 'monthly-2'],
                ['text' => 'The "Export Detailed Matters" button makes an Excel file with all the matters behind the numbers. The "Print" button opens a printable copy.'],
            ],
        ],

        [
            'id' => 'overdue',
            'title' => 'Overdue Matters',
            'description' => 'Open matters sorted by how long they have been with the assistant. It shows calendar days and working days (without weekends and the assistant\'s leave), the stage, the next session date, and a coloured age badge (over 30 / 60 / 90 days).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:OverdueMattersReport'],
            'steps' => [
                ['text' => 'Choose "Overdue Matters". Filters: distribution date, court, matter type, assistant, age, and "No initial report yet".', 'shot' => 'overdue-1'],
                ['text' => 'Start with the oldest matters: click a matter number to open it, and follow up with the assigned assistant.', 'shot' => 'overdue-2'],
            ],
        ],

        [
            'id' => 'court-workload',
            'title' => 'Court Workload',
            'description' => 'Compares the courts: how many matters each court sent, how many are still open, how many are waiting for the initial report, the open share, the revenue billed and the average fee. It answers the question: which court does the work come from, and where is it piling up?',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:CourtWorkloadReport'],
            'steps' => [
                ['text' => 'Choose "Court Workload". Filters: matter distribution date, "With matters only" and "With open matters only".', 'shot' => 'court-workload-1'],
                ['text' => 'Read the table: total matters, still open, awaiting initial report, open share, revenue billed and average fee, with the totals at the end.', 'shot' => 'court-workload-2'],
            ],
        ],

        [
            'id' => 'quality',
            'title' => 'Quality & Rework',
            'description' => 'Tracks the quality markers of each matter: the number of reviews, substantive changes, the court penalty, and final report lateness (working days from the memo date to delivery). These markers are what is actually deducted from the incentive, and this is the only report that brings them together.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MatterQualityReport'],
            'steps' => [
                ['text' => 'Choose "Quality & Rework". Filters: reviewed twice or more, court penalty only, substantive changes only, court, matter type and final report delivery date.', 'shot' => 'quality-1'],
                ['text' => 'The table shows each matter\'s assistant, difficulty, number of reviews and markers. Open the matter to see the details.', 'shot' => 'quality-2'],
            ],
        ],

        [
            'id' => 'assistant-matters',
            'title' => 'Assistant Matters Report',
            'description' => 'A detailed list of each assistant\'s matters: the appointed experts, the parties, the distribution, initial report and final report dates, the fees and collections without VAT, and the notes. The best report for reviewing one assistant\'s files.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:AssistantMattersReport'],
            'steps' => [
                ['text' => 'Choose "Assistant Matters Report". Filters: assistant, experts, matter type, status (such as "In Progress"), and the initial and final report dates.', 'shot' => 'assistant-matters-1'],
                ['text' => 'The "Export" button makes an Excel file of the results, and "Print" gives a paper copy.', 'shot' => 'assistant-matters-2'],
            ],
        ],

        [
            'id' => 'assistant-count',
            'title' => 'Assistant Matters Count',
            'description' => 'A chart and a table of the number of matters in progress with each assistant, so you can share out new matters fairly. The table lists each matter with its status and distribution date.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:AssistantMattersCount'],
            'steps' => [
                ['text' => 'Choose "Assistant Matters Count". A bar shows each assistant\'s number of current files, with the "All Assistant Matters" table below it.', 'shot' => 'assistant-count-1'],
                ['text' => 'Click "Print" for a paper copy.', 'shot' => 'assistant-count-3'],
            ],
        ],

        [
            'id' => 'assistant-fees',
            'title' => 'Assistant Fees Report',
            'description' => 'For each matter whose final report was delivered: its total fees and the "Divided Fees" split among the assistants who worked on it. Use it to see each assistant\'s share of the fees.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:AssistantMatterFeesReport'],
            'steps' => [
                ['text' => 'Choose "Assistant Fees Report". Filters: assistant and final report delivery date.', 'shot' => 'assistant-fees-1'],
                ['text' => 'Columns: matter, court, type, assistant, final report date, total matter fees and divided fees. Use "Export Excel" to save the result.', 'shot' => 'assistant-fees-2'],
            ],
        ],

        [
            'id' => 'performance',
            'title' => 'Assistant Performance',
            'description' => 'One row per assistant bringing together their work, output, earnings and availability: number of matters, completed, still open, completion rate, fees handled, incentive earned (from finalized calculations only) and leave days.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:AssistantPerformanceReport'],
            'steps' => [
                ['text' => 'Choose "Assistant Performance". Filters: matter distribution date, "With matters only" and "With open matters only".', 'shot' => 'performance-1'],
                ['text' => 'Compare the assistants in the table, with the totals row at the end.', 'shot' => 'performance-2'],
            ],
        ],

        [
            'id' => 'aging',
            'title' => 'Fee Collection & Aging',
            'description' => 'What is waiting to be collected, and for how long: for each matter, the net billed, the collected and the outstanding amounts, and the age of the debt in days since it was first billed, coloured by band (0-30, 31-60, 61-90, over 90 days).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:FeeCollectionAgingReport'],
            'steps' => [
                ['text' => 'Choose "Fee Collection & Aging". Filters: "Outstanding only", assistant, court, matter type, age and first billed date.', 'shot' => 'aging-1'],
                ['text' => 'Columns: matter, court/type, assistant, first billed, age, net billed, collected and outstanding, with their totals at the end of the table.', 'shot' => 'aging-2'],
            ],
            'tips' => ['The "Collections Aging" widget on the dashboard also shows a summary of these numbers.'],
        ],

        [
            'id' => 'deductions',
            'title' => 'Deductions Reconciliation',
            'description' => 'For each matter with a deduction fee (office share, discount, and so on): does the money add up? It shows the net billed, the deductions, the net cash received and the "Variance" (billed minus received), and marks each matter "Balanced" or "Check". It also counts wrong-signed fees (a deduction must be stored as a negative amount).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:DeductionsReconciliationReport'],
            'steps' => [
                ['text' => 'Choose "Deductions Reconciliation". Filters: "Not balanced only", "Wrong-signed fees only", deduction type and fee date.', 'shot' => 'deductions-1'],
                ['text' => 'Review the matters marked "Check". To fix wrong-signed fees, use the "Fee Data Maintenance" page in Settings.', 'shot' => 'deductions-2'],
            ],
        ],

        [
            'id' => 'profitability',
            'title' => 'Profitability by Matter Type',
            'description' => 'Which kinds of work really make a profit: for each type, the revenue billed, the collected amount, the incentive paid (from finalized calculations), the net left for the office, the incentive as a percentage of revenue, and the average fee.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:TypeProfitabilityReport'],
            'steps' => [
                ['text' => 'Choose "Profitability by Matter Type". Filters: matter distribution date, "With matters only" and "Active types only".', 'shot' => 'profitability-1'],
                ['text' => 'Read the revenue, collected, incentive and net to office columns in the table.', 'shot' => 'profitability-2'],
            ],
        ],

        [
            'id' => 'vat',
            'title' => 'VAT Summary',
            'description' => 'The VAT charged and collected during the return period, and what is still not collected: the VAT of each fee that carries its own VAT, and the old VAT rows not merged yet. The VAT collected is its share of the fee\'s payments (and the full payments of an old VAT row). VAT is not counted in incentives or in the "Without VAT" figures. What the other reports and tables show as charged or collected is the fee\'s total including VAT.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:VatSummaryReport'],
            'steps' => [
                ['text' => 'Choose "VAT Summary". Set the "Period" (a month or a quarter), and turn on "Not fully collected" if you only want what is still owed.', 'shot' => 'vat-1'],
                ['text' => 'Columns: date, matter, court/type, VAT charged, VAT collected, not yet collected and status, with their totals at the end of the table.', 'shot' => 'vat-2'],
            ],
        ],

        [
            'id' => 'my-matters',
            'title' => 'My Matters',
            'description' => 'A personal report for the assistant: the matters on their desk, which are late, and when their next session is. It relies on the user being linked to their record in the parties, so they see only their own matters.',
            'roles' => ['Assistant (the demo "assistant" role) and anyone with the permission', $admin],
            'permissions' => ['View:MyMattersReport'],
            'steps' => [
                ['text' => 'Sign in with the assistant\'s account, then go to "Reports → My Matters". Filters: "Open only", "Needs initial report", "Session within 7 days" and distribution date.', 'shot' => 'my-matters-1'],
                ['text' => 'The table shows each matter\'s status, next session, days open and initial report.', 'shot' => 'my-matters-2'],
            ],
        ],

        [
            'id' => 'my-incentive',
            'title' => 'My Incentive',
            'description' => 'The assistant\'s personal incentive statement, calculation by calculation (from finalized calculations only): for each matter, the days, fees, rate, deductions and their share, plus the extras, penalties, fixed deduction and the net amount due, with a "Print my statement" button.',
            'roles' => ['Assistant (the demo "assistant" role) and anyone with the permission', $admin],
            'permissions' => ['View:MyIncentiveReport'],
            'steps' => [
                ['text' => 'Sign in with the assistant\'s account, then go to "Reports → My Incentive". If you have no finalized calculations, you will see "Nothing to show yet".', 'shot' => 'my-incentive-1'],
            ],
            'tips' => ['If a matter is split between several assistants, this shows with "Your share".'],
        ],
    ],
];
