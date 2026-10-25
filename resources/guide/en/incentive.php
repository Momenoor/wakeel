<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Incentive Calculations',
    'intro' => 'The system calculates the assistants\' incentive each month from the fees collected on completed matters. Each matter gets a rate based on its difficulty and how many days it took (tiers), set in "Incentive Config". Quality issues (reviews, a late report, a court penalty) are deducted from it, and extra percentages are added for anyone who goes above the month\'s target. The calculation is then finalized and goes into the payroll. Find this section under "Financial → Incentive Calculations".',
    'actions' => [

        [
            'id' => 'list',
            'title' => 'View the incentive calculations',
            'description' => 'Each "calculation" covers a period (usually a month) and has a status: Draft (can be edited and recalculated) or Finalized (locked). At the top of the table: "New Incentive Calculation" and "Export" for the period\'s matters.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:IncentiveCalculation', 'View:IncentiveCalculation'],
            'steps' => [
                ['text' => 'In the side menu, go to "Financial" → "Incentive Calculations".', 'shot' => 'list-1'],
                ['text' => 'The "Export" button opens a window that asks for the "Start Date" and "End Date", then makes an Excel file of the matters in that period.', 'shot' => 'list-export'],
            ],
        ],

        [
            'id' => 'create',
            'title' => 'Create a new incentive calculation',
            'description' => 'The calculation starts empty with a name and a period. Then you import the qualifying matters into it.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:IncentiveCalculation'],
            'steps' => [
                ['text' => 'Click "New Incentive Calculation".', 'shot' => 'create-1'],
                ['text' => 'Enter the "Calculation Name" (for example: Incentive calculation - November 2026), set the "Period Start" and "Period End", and add any "Notes".', 'shot' => 'create-2-filled'],
                ['text' => 'Click "Create". The calculation\'s page opens with the status "Draft".', 'shot' => 'create-3-done'],
            ],
        ],

        [
            'id' => 'import-run',
            'title' => 'Import matters and run the calculation',
            'description' => 'Qualifying matters are those whose final report delivery date (or fee entry date, depending on the trigger type set on the matter type) falls within the period and that meet the collection rules. You import them so they become "lines", then run the calculation to work out the rates, deductions and shares.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:IncentiveCalculation', 'RunCalculation:IncentiveCalculation'],
            'steps' => [
                ['text' => 'Open the calculation (Draft). These buttons show at the top of the page: "Import Qualifying Matters", "Import All Qualifying Matters", "Add Specific Matter", "Run Calculation", "Delete All Lines" and "Finalize".', 'shot' => 'view-draft'],
                ['text' => '"Import Qualifying Matters": choose the "Experts" and "Assistants" to limit the import, then click "Submit". "Import All Qualifying Matters" imports everything that qualifies, with no limit.', 'shot' => 'import-eligible'],
                ['text' => '"Add Specific Matter": for a manual exception. The chosen matter is imported whatever its period or due date (unless its fees are already in another calculation).', 'shot' => 'import-one'],
                ['text' => 'Click "Run Calculation", then "Confirm". Everything calculated before is cleared, and every line is calculated again with the current settings.', 'shot' => 'run'],
            ],
            'tips' => ['You can run the calculation as many times as you like while it is a draft, so do this after changing the incentive settings.'],
        ],

        [
            'id' => 'read',
            'title' => 'Read the calculation: incentive summary and details',
            'description' => 'After the calculation runs, you see the "Calculation Details" (name, period and status), then the "Incentive Summary" table grouped by assistant (you can change the grouping and its direction). For each matter: difficulty, month, days, fees, rate %, base amount, deductions, share, extra and penalty, with the reason for each deduction or extra. Under each assistant\'s name is a totals row.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:IncentiveCalculation', 'Print:IncentiveCalculation'],
            'steps' => [
                ['text' => 'Open the calculation to see the full summary. Use the "Print Summary" button at the top of the page for a paper copy, and the "Print Report for [assistant]" icon next to each assistant for their personal statement.', 'shot' => 'view-draft-tall'],
            ],
        ],

        [
            'id' => 'adjust-line',
            'title' => 'Change a line\'s rate or deduct an amount from an assistant',
            'description' => 'To handle an exception after the calculation without running it again: change an assistant\'s final rate on one matter, or deduct a fixed amount from an assistant\'s total incentive (a fine, for example).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:IncentiveCalculation'],
            'steps' => [
                ['text' => 'The "Edit %" icon on the matter\'s line: enter the "Override %" to make it this assistant\'s actual final rate on this matter, then click "Submit".', 'shot' => 'line-edit-pct'],
                ['text' => 'The "Deduct from [assistant]" icon on their totals row: enter the "Deduction Amount" and the "Reason", then click "Submit".', 'shot' => 'line-deduct'],
            ],
            'tips' => ['Lines changed by hand are marked "override" on the assistant\'s statement.', 'The "Remove … from Calculation" icon takes a matter out of the calculation.', 'The "Add amount or deduction" button above the "Incentive Summary": choose the type ("Additional amount" or "Deduction") and the assistant, enter the "Amount" and the "Reason", then click "Submit" — or use the "Add amount or deduction for [assistant]" icon on any of their lines, which does not ask for the assistant. It shows as a line of its own in the assistant\'s calculation, after their matters; it counts in their total, their statement and payroll, and stays when the calculation is run again. The "Remove" icon on its line takes it out.'],
        ],

        [
            'id' => 'finalize',
            'title' => 'Finalize the calculation',
            'description' => 'Once finalized, the calculation is locked: it cannot be edited or run again. All its fees count as "used", so they do not go into another calculation, and its numbers become available on the assistant\'s "My Incentive" statement and in the payroll run.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Finalize:IncentiveCalculation'],
            'steps' => [
                ['text' => 'Check the numbers, then click "Finalize" and confirm with "Confirm".', 'shot' => 'finalize'],
                ['text' => 'A finalized calculation shows only the summary, without the edit buttons.', 'shot' => 'view-final'],
            ],
            'tips' => ['Finalizing cannot be undone from the screen, so check the calculation carefully first.', 'Deductions are taken once: first from the calculation\'s incentive, and whatever it does not cover (paid shares taken back, or a fixed deduction) from the salary of the payroll month in which the calculation is finalized, on an "Incentive deductions" line. The incentive statements say what will be deducted from the salary.', 'A paid incentive that was taken back stays taken back if its matter comes into the next calculation (it counts towards the month\'s number, with no reward or penalty on it), and a fixed deduction is spread only over the matters that were worked on. The line taken back shows as a negative amount in "My Incentive".'],
        ],

        [
            'id' => 'type-configs',
            'title' => 'Incentive Config (tiers by difficulty)',
            'description' => 'How the calculation works for a matter type: the "Type" (tiered, fixed percentage or committees), the "Assistant Rate" (their share of the incentive), and the "Tiers" table: for each difficulty (easy, medium, hard), a range of days from/to and a percentage. The faster the matter is done, the higher the rate. You link a configuration to types from "Matter Types".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:MatterTypeIncentiveConfig', 'Create:MatterTypeIncentiveConfig', 'Update:MatterTypeIncentiveConfig'],
            'steps' => [
                ['text' => 'Go to "Incentive Calculations" → "Incentive Config".', 'shot' => 'cfg-list'],
                ['text' => 'Click "New Incentive Config": enter the "Name", the "Calculation Type" and the "Assistant Rate (%)".', 'shot' => 'cfg-create'],
                ['text' => 'On the view/edit page, add the "Tiers": difficulty, from day, to day and rate.', 'shot' => 'cfg-view'],
            ],
        ],

        [
            'id' => 'extra-rules',
            'title' => 'Extra % Rules',
            'description' => 'A reward for output: the more matters an assistant completes in the month, the higher the extra percentage added to their incentive. Each rule has a minimum and maximum number of completed matters and an extra percentage.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:IncentiveExtraRule', 'Create:IncentiveExtraRule', 'Update:IncentiveExtraRule', 'Delete:IncentiveExtraRule'],
            'steps' => [
                ['text' => 'Go to "Incentive Calculations" → "Extra % Rules" to see the current rules.', 'shot' => 'extra-list'],
                ['text' => 'Click "New Extra % Rule": enter the "Minimum Completed Matters", the "Maximum Completed Matters" (leave it empty for no upper limit) and the "Extra Percentage (%)".', 'shot' => 'extra-create'],
            ],
            'tips' => ['Matters whose type is marked "Exclude from Incentive Count" are not included in the count.'],
        ],

        [
            'id' => 'meta-adjustments',
            'title' => 'Incentive Adjustments (by a matter property)',
            'description' => 'Changes the incentive rate automatically when a matter has a certain property, such as "Court Penalty" or "Substantive Changes". You set the field name, its value and the percentage adjustment (negative for a deduction).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:IncentiveMetaAdjustment', 'Create:IncentiveMetaAdjustment', 'Update:IncentiveMetaAdjustment', 'Delete:IncentiveMetaAdjustment'],
            'steps' => [
                ['text' => 'Go to "Incentive Calculations" → "Incentive Adjustments".', 'shot' => 'meta-list'],
                ['text' => 'Click "New Incentive Adjustment": enter the "Field Name", the "Field Value" and the "Percentage Adjustment".', 'shot' => 'meta-create'],
            ],
        ],
    ],
];
