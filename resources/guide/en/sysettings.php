<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'System Settings',
    'intro' => 'The system\'s general settings: the name, language, currency and branding, the outgoing mail server and letters, the system announcement and notifications, connecting outside services, and maintenance mode. Each kind has its own page under "Administration → Settings". The settings side menu is divided into "General" (which includes "Themes"), "Communication", "Templates", "Integrations", "Master Data" and "Maintenance". You see only the pages you have permission for.',
    'actions' => [
        [
            'id' => 'general',
            'title' => 'General settings and branding',
            'description' => '"General → General Settings": the system name, the "Company / Organization Name" and "Company address", each in English and Arabic, the "Trade licence number", and the company\'s phone, WhatsApp and email (they show on the legal pages and in letters, and a letter takes the ones that suit its language). Also the default language, the "Date format" and "Time format" for the whole system, the currency and the number of records per page, the "Branding" section to upload the light and dark logos, the browser icon and the default avatar, and the "Calendar" section.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:SystemSettings', 'View:SystemSettingsGeneralTab'],
            'steps' => [
                ['text' => 'Open "Settings → General → General Settings".', 'shot' => 'page-1'],
                ['text' => 'Change the values, then click "Save Settings". The new logo shows at once at the top of the menu and on the sign-in page.', 'shot' => 'tab-general'],
                ['text' => 'Enter the office\'s name and address in English and in Arabic, and the "Trade licence number". In letter templates, {{company.name}} writes the name in the letter\'s language. You can ask for a given language by adding _ar or _en (such as {{company.name_en}}).', 'shot' => 'general-office'],
                ['text' => 'The "Date format" (such as 22/10/2026) and the "Time format" (such as 05:35 PM) are used everywhere: tables, pages, date pickers, emails, PDF files, notifications and letters. In Arabic text the time is written with صباحاً / مساءً. The time zone is set on the server.', 'shot' => 'general-dates'],
                ['text' => 'The "Calendar" section: "\"Send an invitation email\" on when an event is made" sets what the invitation question starts as for each new event (off unless turned on here). "Court sessions\' titles start with" is text written before the matter numbers in the title of a combined court sessions event (empty: the numbers first).', 'shot' => 'general-calendar'],
            ],
        ],
        [
            'id' => 'mail',
            'title' => 'Email settings',
            'description' => '"Communication → Email Settings": the system\'s outgoing email (notifications, approval links and so on). Choose to send from one of the "Mail senders" or from a dedicated server using Microsoft or SMTP, and enter the server, port, encryption, username and password, and the sender\'s email and name. The "Send Test Email" button at the top of the page checks they are right.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:SystemSettings', 'View:SystemSettingsEmailTab'],
            'steps' => [
                ['text' => 'Open "Settings → Communication → Email Settings".', 'shot' => 'tab-mail'],
                ['text' => 'Click "Send Test Email", enter the "Recipient Email Address", then click "Submit" to check.', 'shot' => 'testmail-modal'],
            ],
            'tips' => ['The senders of letters and campaigns are managed separately under "Communication → Mail senders".'],
        ],
        [
            'id' => 'letters',
            'title' => 'Letters & emails',
            'description' => '"Communication → Letters & emails": the format of a letter\'s reference number, the expert types copied in when a letter or minutes is sent by email, the mailbox that letters, minutes and campaigns start from, and the look and font of the PDF kept for every email sent or received.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:SystemSettings', 'View:SystemSettingsLettersTab'],
            'steps' => [
                ['text' => 'Open "Settings → Communication → Letters & emails", change the values, then click "Save Settings".'],
                ['text' => 'In the "Leave Requests" section, the "Leave requests are sent to" field holds the email addresses that receive every new leave request or employee request in one email (with the bell). They are copied on the decision email to the employee. Empty: everyone who can approve leave requests.', 'shot' => 'letters-leave'],
            ],
            'tips' => ['Changing the reference format does not touch letters issued before: they keep their reference.'],
        ],
        [
            'id' => 'notifications',
            'title' => 'The system announcement and chat',
            'description' => '"Communication → Announcements & delivery": the option to show the "Display System Announcement Banner" at the top of every page to alert everyone (such as a maintenance time), with the announcement text, and how many seconds pass between chat checks for new messages. Who is told of what, and by which channel, for each role is set under "Communication → Notification preferences".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:SystemSettings', 'View:SystemSettingsNotificationsTab'],
            'steps' => [['text' => 'Open "Settings → Communication → Announcements & delivery": turn on the options and enter the announcement text, then click "Save Settings".', 'shot' => 'tab-notifications']],
        ],
        [
            'id' => 'integrations',
            'title' => 'Integrations: Microsoft 365, Pusher, scheduled tasks and WhatsApp',
            'description' => '"Integrations → Integrations": connecting the office\'s outside services. Each has a "Connected / Off" badge and a "Turn off" button. A service stays off until its details are filled in: Microsoft 365 (OneDrive folders, the Outlook calendar and mailboxes), Pusher (live notifications and chat), task scheduling through cron-job.org (waiting emails, daily tasks and checking inboxes), and WhatsApp (the business number and access token). Secrets are saved encrypted and never come back to the page. A field left empty keeps what is saved.',
            'roles' => [$admin],
            'steps' => [['text' => 'Open "Settings → Integrations → Integrations" (general manager only), fill in the service\'s details, then click "Save Settings". The cron-job.org link shows once the secret token is generated and saved.']],
            'tips' => ['The matters\' OneDrive folders have their own page, "Settings → Integrations → OneDrive Folders".'],
        ],
        [
            'id' => 'azure',
            'title' => 'Keeping files in Azure Blob Storage',
            'description' => 'The "Azure Blob Storage" section in "Integrations → Integrations": the office\'s own storage account in Azure with a private container in it. It keeps the attached files, signatures and stamps, and the files of emails and minutes and their PDFs, instead of this server. They open only through a proxy, and only for those allowed to see them. Logos, letterheads and fonts stay on the server.',
            'roles' => [$admin],
            'steps' => [
                ['text' => 'Enter the "Storage account name", the "Access key" (Storage account → Access keys; saved encrypted) and the "Container", then click "Save Settings" and "Test connection" (a file is written, read and deleted). Turn on "Keep new files in Azure" so new files are kept there.', 'shot' => 'integrations-azure'],
                ['text' => 'Files kept before stay on this server until they are copied. "Where the files are" counts, for each kind, what is in Azure and what is on the server. "Copy the files to Azure" copies them all in the background, a few files a minute, and you get a message in the notifications when it finishes. Each file is checked against its original before it opens from Azure.'],
                ['text' => 'After copying, "Remove the copies on this server" removes each copy here once its copy in Azure is checked to be the same file (in the background, and you are told by the bell).'],
            ],
            'tips' => ['In the matter\'s files, fees and requests, a "Kept in" column (Azure or this server) shows, with a filter for it.', 'The files of emails and minutes are moved to Azure (removed from the server once they match). Attachments, signatures and stamps keep their originals here until they are removed with the button.'],
        ],
        [
            'id' => 'themes',
            'title' => 'Themes (the interface\'s colours and fonts)',
            'description' => '"General → Themes": interface themes with their colours (primary, secondary, gray and so on, from the Filament palettes or your own colour, for light and dark mode), the navigation (side or top menu), corners, spacing and fonts, with a live preview and a warning if text becomes hard to read. "Add starter themes" adds a ready-made set. "Use for the office" on a theme\'s row makes it the office theme, and "Keep everyone to the office theme" stops users choosing another theme in their profile.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Theme', 'Create:Theme', 'Update:Theme', 'Delete:Theme'],
            'steps' => [
                ['text' => 'Open "Settings → General → Themes". Click "New Theme" for a new theme, or "Edit" or "Use for the office" on a theme\'s row.', 'shot' => 'themes-1'],
            ],
            'tips' => ['Each user chooses their theme under "Profile → Theme", unless the administration keeps everyone to the office theme.'],
        ],
        [
            'id' => 'maintenance',
            'title' => 'Maintenance and offline mode',
            'description' => '"Maintenance → Maintenance & Offline": when "Enable Offline / Maintenance Mode" is on, users see a notice that the system is stopped for a while. You can let only administrators sign in during that time to finish updates.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:SystemSettings', 'View:SystemSettingsMaintenanceTab'],
            'steps' => [['text' => 'Open "Settings → Maintenance → Maintenance & Offline": turn the mode on, choose "Allow Administrators to Access While Offline", then click "Save Settings". Turn it off the same way when you finish.', 'shot' => 'tab-maintenance']],
            'tips' => ['Do not forget to turn the mode off when the work is done, or staff stay unable to sign in.'],
        ],
    ],
];
