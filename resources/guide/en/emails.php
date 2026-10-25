<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Matter emails',
    'intro' => 'The system reads the office\'s mailboxes (and the own mailbox of anyone it is turned on for) and files every email about a matter on that matter for you: a PDF of the email, its original (.eml) and its attachments, in the system and in the matter\'s OneDrive. The same goes for what is sent from the matter. An email whose matter is not certain waits in "Unfiled emails" (side menu → "Communication"). An email the system cannot place, a member of staff forwards to the "Filing mailbox" with the matter number.',
    'actions' => [
        [
            'id' => 'matter-tab',
            'title' => 'The Emails tab on a matter',
            'description' => 'The table shows the "Date", the "Direction" (sent or received), "From / To", the "Subject", and whether the "PDF in OneDrive", "EML in OneDrive" and "Attachments in OneDrive" are saved. An email whose documents came as links to download rather than attachments (a file shared from Google Drive, Dropbox, OneDrive / SharePoint, WeTransfer and the like, or a direct link to a PDF, Word or zip file) shows "Without attachment, n download links" in orange in the attachments column. Signature links (the sender\'s website, social networks, maps, an app to install, privacy, an email address) and Wakeel\'s own links are not counted. Outlook Safe Links and Google redirects are read through to where they lead.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MatterEmailsTab'],
            'steps' => [
                ['text' => 'Open the matter and go to the "Emails" tab. Notice "Without attachment, 2 download links" on the row of the email that sent its documents as links.', 'shot' => 'tab-1'],
                ['text' => 'Click "Details" on the email\'s row: the "Date", "From", "To", "Matter known by" (such as: forwarded to the filing mailbox), whether it is saved in OneDrive, the email\'s "Files", then "Download links": each link opens from there. The linked documents are not saved in Wakeel: open each link and download the document, then add it to the matter\'s "Files".', 'shot' => 'details-1'],
            ],
            'tips' => [
                'Links are read for emails that arrive after this update. For an older email, use "Make the PDF again" and its links are read too.',
                'The "Scan OneDrive" button above the table picks up from OneDrive any PDFs, originals and attachments of received emails that are there.',
                'Every email on the matter has a step in the "Progress" tab and a line in the "History".',
            ],
        ],
        [
            'id' => 'move-remake',
            'title' => 'Move an email to another matter and make its file again',
            'description' => '"Move to another matter" is for an email that arrived and was filed on the wrong matter: the PDF, the original email and the attachments move to the other matter (in the system and in its OneDrive), its step in the matter\'s progress moves with it, and the matter\'s people are told. "Make the PDF again" (from the "…" menu on the row) reads the email again from the mailbox it arrived in: the PDF is made again, the original and attachments are downloaded again and the download links are read again, each replacing the old one. It runs in the background and shows "Being made again: you are told in the bell when it is done." until a notification in the bell tells you what was replaced.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MatterEmailsTab', 'Update:Matter'],
            'steps' => [
                ['text' => 'Click "Move to another matter" on the email\'s row, search for the "Matter" by its number (986, 986/2026 or 2026/986), then click "Submit".', 'shot' => 'move-1'],
            ],
            'tips' => [
                'Two operations cannot run on the same email at once (filing it and making it again, for example). If you see "Something is already being done to this email. Try again in a minute.", try again after a minute.',
                'The email\'s PDF says at the top of each page whether it was received ("Inbox – mailbox – app") or sent ("Sent – …").',
            ],
        ],
        [
            'id' => 'unfiled',
            'title' => 'Unfiled emails',
            'description' => 'Emails whose matter is not certain wait here (from parties in more than one matter with nothing to say which, or any other email in a mailbox that keeps all its emails). Above the table are an "All" tab and a tab for each mailbox. Each user sees only the mailboxes they are allowed (Users → Edit → Work account). Each email shows "Received", "From", "Subject" and "May be about" (the possible matters in short: their number, parties, level, type, stage and status), with the buttons "Open", "Attachments (n)", "Download links (n)", "File to matter", "Ignore sender" and "About no matter".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:UnfiledEmails', 'Update:Matter (to file it on the matter)', 'View:SystemSettings (to ignore the sender)'],
            'steps' => [
                ['text' => 'In the side menu, go to "Communication" → "Unfiled emails". The number next to it is how many are waiting, and the tabs above the table are for the mailboxes you are allowed.', 'shot' => 'unfiled-1'],
                ['text' => 'Click "Attachments (1)" to see the email\'s attachments and open them before you file it.', 'shot' => 'unfiled-attachments'],
                ['text' => 'Click "Download links (1)" to see the links of documents that were sent as links.', 'shot' => 'unfiled-links'],
                ['text' => 'Click "File to matter", choose the "Matter", then click "Submit". It is filed in the background, a notification tells you when it is done, and it leaves the list.', 'shot' => 'unfiled-file'],
                ['text' => 'For several emails at once, tick them at the start of the rows, then open "Actions": "File to matter" (each in the background; any you may not file on that matter are left), "Ignore senders", "About no matter", "Make the PDF again".', 'shot' => 'unfiled-bulk'],
            ],
            'tips' => [
                '"About no matter" sets the email aside and deletes what was saved of it (the PDF and attachments). It stays in the mailbox.',
                '"Ignore sender" sets aside their waiting emails, and their new emails are not read. To take the address off the ignore list, go to "Settings → Integrations".',
                'When an email is filed or moved by hand, the matter\'s people are told, and it shows as read in the bell of whoever filed it. The question "Which matter is this email from … about?" is marked read for everyone.',
            ],
        ],
        [
            'id' => 'filing-mailbox',
            'title' => 'Filing by forwarding to the filing mailbox',
            'description' => 'For an email the system cannot place: forward it from your office mailbox to the "Filing mailbox" (a mailbox set aside for this in Mail senders) and write the matter number (such as 7777/2026) in the subject or on the first line, or in the address itself (archive+7777-2026@…). The original email is filed on that matter as if it came from its own sender: if it was forwarded as an attachment, it is the email itself; otherwise it is read from the quoted text (Outlook in Arabic or English, and Gmail). If it was waiting in "Unfiled emails", it leaves that list. If it mentions more than one matter, it stays in "Unfiled emails" for the people of those matters to choose.',
            'roles' => ['Every user who sends from their office mailbox, on a matter they may work on'],
            'permissions' => [],
            'steps' => [
                ['text' => 'In Outlook, forward the email (preferably with "Forward as Attachment") to the filing mailbox, and write in the subject, for example, "FW: 7777/2026 documents". It then appears in the matter\'s "Emails" tab, and "Details" says "Matter known by: forwarded to the filing mailbox".', 'shot' => 'details-1'],
            ],
            'tips' => [
                'The bell tells you what happened: it was filed, the matter number was not found, you have no permission on the matter, or it could not be confirmed that it came from you.',
                'The filing mailbox is set in "Settings → Communication → Mail senders" (see the "Mail senders" section).',
                'The "Emails" tab in the bell shows the emails received for your matters. A reply filed on a matter is reported to its expert, its assistant and the super admin.',
            ],
        ],
    ],
];
