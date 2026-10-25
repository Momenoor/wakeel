<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Financial Settings',
    'intro' => 'The figures the incentive and payroll calculations rely on: incentive rates and the monthly minimum, the deduction switches, the rules for loans and the journal voucher, and the legal figures for end of service and leave. A change affects future calculations only. Find them under "Settings → Financial Settings".',
    'actions' => [
        [
            'id' => 'incentive',
            'title' => 'Incentive settings',
            'description' => 'The "Incentive" tab: "Rates & Thresholds" and "Deduction Toggles". It also has links to the "Type Configurations", the "Extra % Rules" and the "Meta Adjustments".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:FinancialConfiguration'],
            'steps' => [
                ['text' => 'Under "Settings → Master Data", choose "Financial Settings", then the "Incentive" tab.', 'shot' => 'tab-incentive'],
                ['text' => 'Set the "Minimum Matters Per Month" (the quota), the "Below-Minimum Penalty (%)" (applied to every matter in a month when the assistant fell short), the "Committee Fixed Percentage (%)" (for matters commissioned by committees, whatever their type) and the "Office Work Adjustment (%)" (added when a matter is flagged as office work).'],
                ['text' => 'Each deduction switch turns one rule on or off: "Enable First Review Deduction", "Enable Subsequent Review Deduction", "Enable Late Final Report Deduction", "Enable Below-Minimum Monthly Penalty" and "Enable Court Penalty Full Exclusion". Then click "Save Settings".'],
            ],
            'tips' => ['Lines already calculated do not change until you click "Run Calculation" again.'],
        ],
        [
            'id' => 'payroll',
            'title' => 'Payroll, journal voucher and legal figures',
            'description' => 'The "Payroll" tab: the instalment rounding, the bank transfer fee and the flight ticket account in the journal voucher, the details for the salary authorization form (WPS), and the figures for end-of-service gratuity, annual leave and sick leave under Federal Decree-Law 33 of 2021.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:FinancialConfiguration'],
            'steps' => [
                ['text' => 'Open the "Payroll" tab. Under "Loan & Petty Cash Recovery", enter the "Instalment Rounding Step (AED)". Under "Journal Voucher", enter the "Bank Transfer Fee (AED)" (a fixed charge for each run, VAT included) and the "Flight Ticket GL Account".', 'shot' => 'tab-payroll'],
                ['text' => 'Under "Salary Authorization Form (WPS)", enter the "WPS Employer ID", the "Trade License Number" and the "GL Number" (the office\'s account with the exchange house).'],
                ['text' => 'The "End of Service Gratuity", "Annual Leave" and "Sick Leave" sections hold legal figures: the days per month used to work out the daily rate, the cap in months, the minimum service in years, the days earned each year, and the sick leave pay bands (full pay, half pay, unpaid). Change them only if the law changes or on legal advice, then click "Save Settings".'],
            ],
            'tips' => ['Lowering the end-of-service figures below the legal minimum exposes the office to liability.'],
        ],
        [
            'id' => 'vat',
            'title' => 'VAT rate',
            'description' => 'The "VAT" tab (for everyone who can open the page): the "VAT rate" (5%) used to work out the VAT on every fee. It is added to a fee entered without VAT, and taken out of a fee entered with VAT ("Including VAT"). It is also used for the bank fee on the salary authorization form. Fees saved before keep their VAT.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:FinancialConfiguration'],
            'steps' => [
                ['text' => 'Open the "VAT" tab, enter the rate, then click "Save Settings".', 'shot' => 'tab-vat'],
            ],
        ],
    ],
];
