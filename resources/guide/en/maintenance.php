<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Maintenance and update pages',
    'intro' => 'Tools for the technical administrators to check the system\'s data, repair it and update the system. Every page carries a warning: back up the database before any repair, and try the preview first where there is one. Find them under "Administration → Settings → Maintenance".',
    'actions' => [
        [
            'id' => 'access',
            'title' => 'Access Control Maintenance',
            'description' => 'Checks and repairs the roles\' permissions: (1) "Align Super Admin Roles" (makes the roles the administrators hold the same), (2) "Repair Unreachable Abilities" (permissions given to roles that cannot reach the screens they belong to), and the "Stale Permissions" report (permissions that exist but nothing uses).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:AccessControlMaintenance'],
            'steps' => [['text' => 'Open "Access Control Maintenance", read "Before you run anything", look through the three reports, then run the repair you need with its buttons.', 'shot' => 'access-1']],
        ],
        [
            'id' => 'fees',
            'title' => 'Fee Data Maintenance',
            'description' => 'Repairs past errors in fees and collections, step by step: "0. Align Allocation Signs", "1. Remove Duplicate Allocations", "2. Trim Over-Collection", "3. Settle Non-Owner Matters in Full", and "Correct Deduction Fee Signs" and recalculating the fee statuses. You need to set the "Office Owner" (the expert who owns the office) and review the "Pending Repairs" before you run anything.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:FeeDataMaintenance'],
            'steps' => [['text' => 'Open "Fee Data Maintenance", read the warning, choose the "Office Owner", then run the steps in order after checking what will change.', 'shot' => 'fees-1']],
            'tips' => ['Use the "Deductions Reconciliation" report to see what needs repairing before you start.'],
        ],
        [
            'id' => 'fees-vat',
            'title' => 'Merge VAT into the fees and restore who entered each fee',
            'description' => 'Since every fee now carries its own VAT, older fees are put right here. "Merge VAT into the fees and fix statuses" merges each old "VAT" line into the fee it is the VAT of, and its payments move with it. Then every fee\'s status and every matter\'s collection status is worked out again, comparing what was collected with the total including VAT. "Restore who entered each fee" gives each fee back the person who entered it, from the activity log. "Recalculate Fee & Collection Statuses" corrects the statuses without making whoever runs it the person who entered the fees. The merge and the restore first show a preview with how many records will change and a log of every change. Nothing happens until you click "Apply", and then you can download the log of what was applied.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:FeeDataMaintenance'],
            'steps' => [
                ['text' => 'Open "Settings → Maintenance → Fee Data Maintenance". The new buttons are at the top of the page: "Merge VAT into the fees and fix statuses", "Restore who entered each fee" and "Recalculate Fee & Collection Statuses".', 'shot' => 'fees-2-actions'],
                ['text' => 'Click "Merge VAT into the fees and fix statuses". The window shows what will be merged (the number of lines and fees, and the matters whose statuses will change), and "See the preview log" lists every change, each fee as the merge will leave it. Check it, then click "Apply", or "Cancel". After you apply, "Download the log" compares the merged fees and their matters with their statuses from before the merge.', 'shot' => 'fees-3-merge-preview'],
            ],
            'tips' => ['Before you apply, back up the database (System Updates → Database backups). It is safe to run again: a second run finds nothing to do.'],
        ],
        [
            'id' => 'difficulty',
            'title' => 'Fix Matters Difficulty',
            'description' => 'Makes the "Difficulty" values of old matters, saved in different forms, the standard ones ("Easy", "Medium", "Hard"), with statistics showing how the current values are spread.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:FixMattersDifficulty'],
            'steps' => [['text' => 'Open "Fix Matters Difficulty", look at the "Current Statistics", then click "Fix Difficulty Values".', 'shot' => 'difficulty-1']],
        ],
    ],
];
