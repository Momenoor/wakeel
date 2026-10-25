<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Dashboard',
    'intro' => 'The home page of the matters system: number cards, tables and charts that sum up what needs your attention today. Each widget has its own view permission, so users see only the widgets their role allows. An admin sees them all; an assistant may see only some.',
    'actions' => [
        [
            'id' => 'stats',
            'title' => 'Number cards (needs attention + matter statistics)',
            'description' => 'Two rows of cards. The first shows what needs attention: Unassigned Matters, Open Requests, Awaiting Final Report and Sessions This Week. The second shows matter statistics: finalized, in progress, unpaid and total. Click a card to open the matching list.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:AdminDashboard', 'View:AttentionNeededWidget', 'View:MatterStatsWidget'],
            'steps' => [
                ['text' => 'The "needs attention" cards appear at the top of the dashboard.', 'shot' => 'widget-0'],
                ['text' => 'The matter statistics cards are below them.', 'shot' => 'widget-1'],
            ],
        ],
        [
            'id' => 'upcoming',
            'title' => 'Upcoming Sessions',
            'description' => 'A table of the sessions scheduled in the next 14 days: the time, the matter, the court and the assistant. Click a matter number to open the matter.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:UpcomingSessionsWidget'],
            'steps' => [['text' => 'Look through the table. Use "Per page" or the page links at the bottom to see more.', 'shot' => 'widget-2']],
        ],
        [
            'id' => 'calendar',
            'title' => 'Calendar (agenda)',
            'description' => 'The office calendar, shown by day, week, month or as a list. It shows sessions, inspections and meetings. Move between dates with the arrows and "today". Click an event to view or edit it. Click an empty day, or drag across one, to create a new event.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:CalendarWidget'],
            'steps' => [['text' => 'Use the "day / week / month / list" buttons at the top of the calendar to change the view.', 'shot' => 'widget-3']],
            'tips' => ['How to create an event and sync it with Outlook is explained in the "Calendar Events" section.'],
        ],
        [
            'id' => 'charts',
            'title' => 'Collections Aging and Matters Received Per Year',
            'description' => 'Two charts. "Collections Aging" shows the amount still owed in AED, split by age: 0-30, 31-60, 61-90 and over 90 days. "Matters Received Per Year" shows how many matters came in each year. Point at a bar to read its value.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:CollectionsAgingWidget', 'View:MattersPerYearWidget'],
            'steps' => [
                ['text' => 'The "Collections Aging" chart shows how much overdue money is owed.', 'shot' => 'widget-4'],
                ['text' => 'The "Matters Received Per Year" chart shows how the workload has grown.', 'shot' => 'widget-5'],
            ],
        ],
        [
            'id' => 'vacation',
            'title' => 'Leave / Vacation Calendar',
            'description' => 'A monthly calendar of staff absences (approved leave), so the manager knows who is away before assigning matters or setting appointments.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:VacationCalendarWidget'],
            'steps' => [['text' => 'The employee\'s name shows on the days they are away. Use the arrows to move between months.', 'shot' => 'widget-6']],
        ],
        [
            'id' => 'unmatched',
            'title' => 'Events with matters not in the system (for admins)',
            'description' => 'When a calendar event\'s title mentions a matter number that is not in the system, admins see a pop-up (once an hour) listing these events and the missing number. A widget on the dashboard lists them too, so you can add the matter or correct the title.',
            'roles' => ['Super admin (super-admin) and anyone with the "admin" permission'],
            'permissions' => ['View:UnmatchedEventReferencesWidget'],
            'steps' => [['text' => 'When the pop-up appears, click "Open dashboard" to see the details. Then add the missing matter or edit the event\'s title.']],
        ],
    ],
];
