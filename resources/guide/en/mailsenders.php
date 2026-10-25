<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Mail senders',
    'intro' => 'The mailboxes the system sends letters, campaigns and minutes from, and reads incoming email from: a Microsoft 365 account or another mail server (SMTP or cPanel). Each mailbox has a name, an address and an email signature, and two options: "Keep every email of this inbox" and "Filing mailbox". Some may also be defined in the configuration files. Find them under "Settings → Communication → Mail senders".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the senders',
            'description' => 'The table shows each mailbox, its "Mailbox" (shared, or personal to a user) and its status (active), and the "Keeps every email" and "Filing mailbox" columns. Each sender has "Send test email", edit and delete.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:MailSender'],
            'steps' => [['text' => 'In the side menu, go to Settings → Communication → "Mail senders".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Add a mail sender',
            'description' => 'Choose the type of account, then enter its details. The password is saved encrypted and is not shown after saving.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:MailSender'],
            'steps' => [
                ['text' => 'Click "New Mail sender". Enter the "Email address" and choose "Hosted on": "Microsoft 365" (needs the Azure app set up in the environment file) or "cPanel / other mail server (SMTP)" (this shows fields for the server, port, encryption and password).', 'shot' => 'create-1'],
                ['text' => 'Enter the "Sender name" (as the recipient sees it) and the "Code" (an internal identifier that must be unique), and leave it "Active". In the "Signature" section, write the signature added to the emails, then click "Create".'],
            ],
            'tips' => ['"Keep every email of this inbox" is an option for each mailbox. Off: only replies and emails from the matters\' parties are kept from the mailbox. On: every other email too (except the office\'s own) goes to "Unfiled emails", to be filed on a matter or set aside.'],
        ],
        [
            'id' => 'filing',
            'title' => 'Set the filing mailbox',
            'description' => 'A mailbox set aside for filing emails whose matter cannot be worked out. A member of the office staff forwards the email to it with the matter number (1200/2026) in the subject or the first line, or in the address (name+1200-2026@…). The original email is then filed on that matter as if it came from its own sender. Everything it receives is treated as filing instructions, not correspondence, so use it for this only.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:MailSender'],
            'steps' => [
                ['text' => 'In the senders table, the filing mailbox shows a mark in the "Filing mailbox" column.', 'shot' => 'filing-1'],
                ['text' => 'Click "Edit" on the mailbox\'s row, turn on "Filing mailbox", then click "Save changes".', 'shot' => 'filing-2'],
            ],
            'tips' => ['How staff use it is explained in "Matter emails → Filing by forwarding to the filing mailbox".'],
        ],
        [
            'id' => 'test',
            'title' => 'Send a test email',
            'description' => 'To make sure the mailbox settings are right before you rely on it for a campaign or a letter.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:MailSender'],
            'steps' => [['text' => 'Click the "Send test email" icon on the sender\'s row and enter an address to receive the email. If it does not arrive, check the server details or the password.']],
        ],
    ],
];
