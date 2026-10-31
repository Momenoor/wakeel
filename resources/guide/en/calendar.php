<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Calendar Events',
    'intro' => 'The office calendar: court sessions, site inspections and meetings. You can link each event to one matter or to several. When you save it, it can update the matter\'s "Next Session Date" for you, sync to the Outlook calendar and get a Microsoft Teams meeting. Find it in the side menu → "Calendar Events".',
    'actions' => [

        [
            'id' => 'list',
            'title' => 'View the events',
            'description' => 'The table shows the event\'s title, the linked matter, the type (single or bulk), its start and end, whether it is synced to Outlook, whether it has a Teams meeting, and "Made in": "Created in Wakeel" or "From Outlook, Teams or a phone" (also shown on the event\'s page, and in grey on the calendar with its tooltip). The default filter, "Upcoming", can be removed. You can also filter by type, by sync and by "Made in".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:CalendarEvent', 'View:CalendarEvent'],
            'steps' => [
                ['text' => 'In the side menu, choose "Calendar Events". Above the table are the buttons "Create Single Event", "Create Bulk Event" and "Link all events to matters".', 'shot' => 'list-1'],
                ['text' => 'At the start of each row are icons: delete, edit, view, and a "Sync to Outlook" link for events not synced yet.', 'shot' => 'list-row-actions'],
                ['text' => 'The eye icon shows the event\'s details in a window.', 'shot' => 'view-1'],
                ['text' => 'The "Made in" column and its filter separate what was created in the system from what came from Outlook, Teams or a phone.', 'shot' => 'list-made-in'],
            ],
        ],

        [
            'id' => 'create-single',
            'title' => 'Create a single event',
            'description' => 'One event (a session, site inspection or meeting), linked to a matter if you want. When you choose the matter, the system fills in the title and description from the matter\'s details (the court, the type, the parties and the experts).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['CreateSingle:CalendarEvent'],
            'steps' => [
                ['text' => 'Click "Create Single Event".', 'shot' => 'create-single-1'],
                ['text' => 'Choose the "Matter". The title and description are written for you, and you can edit them. Set the "Start At", "End At" and "Location". Turn on "All Day" for events with no time. "Update matter\'s next session date" makes this event the matter\'s next session. Choose what you want in "Sync to Outlook Calendar" and "Create Teams Meeting" (they work if Outlook is set up).', 'shot' => 'create-single-2-filled'],
                ['text' => 'Click "Submit". An "Event Created Successfully" message appears and the event shows in the table.', 'shot' => 'create-single-3-done'],
            ],
            'tips' => ['If the Outlook sync fails, the event is still saved in the system and a message tells you why. You can sync it later from the "Sync to Outlook" link.', 'When syncing to Outlook is on, a "Send an invitation email to the matter\'s assistant" switch appears (on single and bulk events). It starts at the value set in "General Settings → Calendar". See "Reminders and inviting the assistant" below.', 'While you add an event, the events of the same day are shown. If it overlaps another, you are asked: "Cancel" to go back to the form as you filled it, or "Create it anyway".'],
        ],

        [
            'id' => 'create-bulk',
            'title' => 'Create a bulk event for several matters',
            'description' => 'When several matters are heard at the same court in one session, you create one bulk event for all of them instead of entering each one.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['CreateBulk:CalendarEvent'],
            'steps' => [
                ['text' => 'Click "Create Bulk Event", then choose the "Matter Type" and the "Court". The list of matching matters appears.', 'shot' => 'create-bulk-1'],
                ['text' => 'Tick the matters to be heard in the session, and set the "Session Date & Time", the end and the location. The "Title" is made for you from the matter numbers. Turn on the Outlook sync and the Teams meeting if you want, then click "Submit".', 'shot' => 'create-bulk-2-filled'],
            ],
        ],

        [
            'id' => 'link-all',
            'title' => 'Link all events to matters',
            'description' => 'The system reads the titles of all events (including those imported from Outlook) and links each event to the matter whose number (such as 639/2025) its title mentions. Links made before are left as they are.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:CalendarEvent'],
            'steps' => [
                ['text' => 'Click "Link all events to matters", then "Confirm" in the window.', 'shot' => 'link-all-1'],
            ],
            'tips' => ['Events that mention a matter number not in the system appear in the "Events with a matter number not in the system" widget on the administrators\' dashboard.'],
        ],

        [
            'id' => 'edit-delete',
            'title' => 'Edit or delete an event',
            'description' => 'You can edit any event (the title, the linked matters, the date, the location, the description) or delete it. Deleting also deletes it from Outlook if it is synced. When you edit a synced event here, the change is sent to Outlook in the background and the bell tells you "Updated in Outlook" (or the reason it was not updated). "Sync to Outlook" on any event sends it again as it is in the system, the same way.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:CalendarEvent', 'Delete:CalendarEvent', 'SyncToOutlook:CalendarEvent (to sync it)'],
            'steps' => [
                ['text' => 'Click the pencil icon on the event\'s row, change what you need, then click "Save changes". On a bulk event, a "Linked matters" field lets you add or remove matters. The bin icon deletes it.', 'shot' => 'edit-1'],
            ],
        ],

        [
            'id' => 'reminders',
            'title' => 'Reminders and inviting the assistant',
            'description' => 'Every single event linked to a matter reminds the matter\'s assistant 24 hours and 1 hour before: a reminder in their Outlook calendar (also shown in Teams and on their phone) that is removed after it rings, and a notification from the system by the bell, email or WhatsApp, as they chose in "My notifications". The event itself stays in the shared calendar. The reminder moves with it if its time changes, and is deleted when it is deleted. Bulk events have no reminders. The "Send an invitation email to the matter\'s assistant" switch is optional on every new event sent to Outlook: on, the assistant gets an invitation email; off, the event stays in the shared calendar and only its reminders are added to the assistant\'s calendar. The reminders arrive either way.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['CreateSingle:CalendarEvent', 'CreateBulk:CalendarEvent'],
            'steps' => [
                ['text' => 'In the "Create Single Event" or "Create Bulk Event" form, turn on "Sync to Outlook Calendar", then decide on "Send an invitation email to the matter\'s assistant". The value it starts at is set in "Settings → General → General Settings → Calendar".', 'shot' => 'sysettings/general-calendar'],
            ],
            'tips' => ['No reminder? Reminders go to the matter\'s assistant on single events only. A mailbox that refuses the reminder is tried again later, then left after 8 tries.'],
        ],
    ],
];
