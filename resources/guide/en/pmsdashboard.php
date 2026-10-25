<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Property dashboard',
    'intro' => 'The home page of the property management system: a summary of collections, upcoming instalments and revenue. At the top are two filters, "Owner Group" and "Building", that narrow everything on the dashboard to one owner group or one building.',
    'actions' => [
        [
            'id' => 'overview',
            'title' => 'Summary cards',
            'description' => 'Three cards: "Vacant Units" (vacant units out of the total), "Overdue Instalments" (how many, and the amount outstanding), and "Renewals Due (90 Days)" (leases that end within the next 90 days).',
            'roles' => ['Every user who can open the property panel', 'Super admin (super-admin), always'],
            'permissions' => ['View:PMSOverviewWidget'],
            'steps' => [
                ['text' => 'Choose an "Owner Group" or a "Building" at the top of the dashboard to narrow the figures. The three cards appear below them.', 'shot' => 'page-1'],
                ['text' => 'A close-up of the three cards: vacant units, overdue instalments and renewals due.', 'shot' => 'widget-0'],
            ],
        ],
        [
            'id' => 'upcoming',
            'title' => 'Upcoming Instalments (Next 7 Days)',
            'description' => 'A table of instalments due soon: due date, tenant, building, units, amount due and the instalment status (Pending…). Use it every day to follow up collections.',
            'roles' => ['Every user who can open the property panel'],
            'permissions' => [],
            'steps' => [['text' => 'Look through the table. To record a payment, open the matching lease and record it on the instalment.', 'shot' => 'widget-1']],
        ],
        [
            'id' => 'revenue',
            'title' => 'Revenue (Last 12 Months)',
            'description' => 'A line chart of the revenue collected each month over the last twelve months.',
            'roles' => ['Every user who can open the property panel'],
            'permissions' => ['View:PmsRevenueChartWidget'],
            'steps' => [['text' => 'Hover over a point on the line to read that month\'s revenue.', 'shot' => 'widget-2']],
        ],
    ],
];
