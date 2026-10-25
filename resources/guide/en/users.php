<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Users',
    'intro' => 'The accounts that sign in to the system. Each user has a name, an email, a password and an avatar, a way to receive notifications, a link to their record in the parties, and most importantly the "Roles" that set what they can do. Find this section under "Administration → Users".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the users',
            'description' => 'The table shows all users with their pictures and when they were last seen (online/offline). Each row has icons: view, edit, change password, delete, impersonate user and sign out.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:User', 'View:User'],
            'steps' => [
                ['text' => 'In the side menu, go to "Administration" → "Users".', 'shot' => 'list-1'],
                ['text' => 'The icons at the end of each row give you the quick actions.', 'shot' => 'list-row-actions'],
            ],
        ],
        [
            'id' => 'create',
            'title' => 'Add a user',
            'description' => 'The account is created with a name, an email and a first password. Then you set the roles, the notification details and the link to a record in the parties (so the assistant shows on their matters).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:User'],
            'steps' => [
                ['text' => 'Click "New User". Upload the "Avatar" by dragging the picture in or clicking "Browse".', 'shot' => 'create-1'],
                ['text' => 'Enter the "Name", "Email", "Password", "Password Confirmation" and "Display name" (the name shown). "Party" links the user to the party record, "Notify by Whatsapp" and "Notify by Email" set the channels they are notified by, and "Roles" lets you choose one role or more.', 'shot' => 'create-2-filled'],
                ['text' => 'Click "Create". The new user appears and can sign in straight away.', 'shot' => 'create-3-done'],
            ],
            'tips' => ['A user with no role sees nothing but the pages open to everyone (the dashboard, the profile and the user guide).'],
        ],
        [
            'id' => 'edit',
            'title' => 'Edit a user',
            'description' => 'To change the name, email, roles, notification channels or picture, and the work account (see the next action).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:User'],
            'steps' => [['text' => 'Click "Edit" on the user\'s row, change what you need, then click "Save changes".', 'shot' => 'edit-1']]
        ],
        [
            'id' => 'work-account',
            'title' => 'The work account and shared mailboxes',
            'description' => 'The user\'s page (edit or view) has two columns, with a "Work account" section: one Microsoft 365 account for OneDrive, the calendar and the user\'s own mailbox, reached through the office\'s app registered with Microsoft, with no password. "Read and file its emails" (with "Which emails": "Only those about a matter" or "Every email"; an email whose matter is not known waits in this user\'s own "Unfiled emails"), "Send from it" (in their own "Send from" list only, not anyone else\'s), and the "Signature" at the end of their emails (empty: the office\'s signature). Then "May send from the office\'s shared mailboxes" and "Shared mailboxes they may use": they send only from these, and in "Unfiled emails", its tabs and its files they see only what reached them. If all of them are ticked, any shared mailbox added later is open to them too. Last, "In Unfiled emails, from the shared mailboxes": "All of them" or "Those that may be about their matters". An assistant also has an "Assistant" section: their matters\' OneDrive folders, "Report reviewers (WhatsApp)" and "Administrators of their matters".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:User'],
            'steps' => [
                ['text' => 'Click "Edit" on the user\'s row and scroll down to "Work account": enter the "Microsoft 365 account" (empty: the sign-in email itself) and turn on what is needed.', 'shot' => 'work-account-1'],
                ['text' => 'Tick the "Shared mailboxes they may use" ("Deselect all" clears the ticks), choose which of them they see in Unfiled emails, then click "Save changes".', 'shot' => 'work-account-2'],
            ],
            'tips' => ['A user whose mailboxes are all still ticked sees every shared mailbox, including any added later.'],
        ],
        [
            'id' => 'password',
            'title' => 'Change a user\'s password',
            'description' => 'The administrator sets a new password for someone who forgot theirs, without needing to know the old one.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:User'],
            'steps' => [['text' => 'Click the "Change Password" icon on the user\'s row, enter the "Password" and "Password Confirmation" (the eye icon shows them), then click "Confirm".', 'shot' => 'password-modal']],
        ],
        [
            'id' => 'impersonate',
            'title' => 'Impersonate a user',
            'description' => 'The administrator enters the system as the other user, to see what they see and fix their problem or check their role\'s permissions. A bar reminds them they are impersonating, with a button to go back to their own account.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Impersonate:User'],
            'steps' => [['text' => 'Click the "Impersonate User" icon, then click "Confirm".', 'shot' => 'impersonate-modal']],
            'tips' => ['Use it to test a new role before giving it to everyone, and go back to your own account as soon as you finish.'],
        ],
        [
            'id' => 'signout',
            'title' => 'Sign a user out of all their devices',
            'description' => 'Ends all the user\'s sessions on every browser and device, so they must sign in again. Useful when a device is lost or an employee is stopped. You can also do this for several users at once by selecting them.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:User'],
            'steps' => [['text' => 'Click the "Sign out" icon on the user\'s row, then click "Sign out".', 'shot' => 'signout-modal']],
            'tips' => ['The "Sign out" a user clicks in their own menu ends the session on that device only, and "Remember me" stays on their other devices. To sign out of every device: use this action, or change the password.'],
        ],
    ],
];
