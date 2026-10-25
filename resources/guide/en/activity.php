<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Activity logs and the audit dashboard',
    'intro' => 'The system records every significant action: who did what, when, from which IP address, and exactly what changed. This is the audit trail you use to settle any dispute or spot tampering. Find it under "Administration → Activity Logs" and "Audit Dashboard".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the activity logs',
            'description' => 'The table shows the event, the record, who did it, the type of item and the time of the action. The two tabs are "All activity" and "High risk" (sensitive actions such as permanent deletion and permission changes).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Activity', 'View:Activity'],
            'steps' => [
                ['text' => 'In the side menu, go to "Administration" → "Activity Logs".', 'shot' => 'list-1'],
                ['text' => 'Click the "High risk" tab to see only the sensitive actions.', 'shot' => 'tab-risk'],
            ],
        ],
        [
            'id' => 'filter',
            'title' => 'Search and filter',
            'description' => 'Search by description or by who did it, or filter by event type (created/updated/deleted), record, user or date.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Activity'],
            'steps' => [['text' => 'Click the "Filter" icon (the number shows how many filters are active), make your choices, then apply them.', 'shot' => 'filters']],
        ],
        [
            'id' => 'view',
            'title' => 'View the details of an action',
            'description' => 'The action\'s page shows the description, who did it and the item, a table of the "Old" and "New" values of each field that changed, and the device details.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Activity'],
            'steps' => [['text' => 'Click "View" on the action\'s row.', 'shot' => 'view-1']],
        ],
        [
            'id' => 'export',
            'title' => 'Export the logs and retention holds',
            'description' => '"Export Activity Logs" creates a file with the columns you choose. "Retention holds" set how long logs are kept before they are cleaned up automatically.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Activity'],
            'steps' => [
                ['text' => 'Click "Export Activity Logs", turn on the columns you need and rename them if you like, then click "Export".', 'shot' => 'export'],
                ['text' => '"Retention holds" is where you set how long logs are kept.', 'shot' => 'retention'],
            ],
        ],
        [
            'id' => 'audit',
            'title' => 'Audit Dashboard',
            'description' => 'A visual view of system activity: statistics cards, the "Activity Over Time" chart, an "Activity Heatmap" that shows the busiest times of use, and the latest activity.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:AuditDashboard', 'View:ActivityStatsWidget', 'View:ActivityChartWidget', 'View:ActivityHeatmapWidget', 'View:LatestActivityWidget'],
            'steps' => [['text' => 'In "Administration", choose "Audit Dashboard".', 'shot' => 'audit-1']],
        ],
    ],
];
