<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Bulk Email',
    'intro' => 'The office sends one message to dozens of recipients, with each message personalised with the recipient\'s name and details (such as the matter number and the amount). There is a daily sending limit and optional scheduling, and you can follow what was sent, what failed, and try again. Find it under "Communication → Bulk Email".',
    'actions' => [

        [
            'id' => 'list',
            'title' => 'View the campaigns',
            'description' => 'The table shows each campaign\'s name, the sender, its status (Draft, Active, Paused, Completed, Cancelled), the sending progress (sent out of the total, and failed) and the scheduled time.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:BulkMailCampaign', 'View:BulkMailCampaign'],
            'steps' => [
                ['text' => 'In the side menu, go to "Communication" → "Bulk Email". At the top of the table are the "New Bulk Email Campaign" and "Import sent emails" buttons.', 'shot' => 'list-1'],
            ],
        ],

        [
            'id' => 'create',
            'title' => 'Create a bulk email campaign',
            'description' => 'In the campaign you set the message, the sending mailbox and the daily sending limit. Then you add the list of recipients from the campaign page. You write the text in a rich editor, and put placeholders in double braces, such as {{name}}, to be replaced with each recipient\'s name.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:BulkMailCampaign'],
            'steps' => [
                ['text' => 'Click "New Bulk Email Campaign". The page has these sections: "Campaign Details", "Recipients & CC", "Placeholders Definition", "Attachment" and "Schedule & Limits".', 'shot' => 'create-1'],
                ['text' => 'Enter the "Name" (for internal use) and the "Subject" (placeholders such as {{name}} work here). Choose the "From Sender": the mailbox the email goes out from (a "Signature Preview" shows below it). Write the "Body" in the editor.', 'shot' => 'create-2-filled'],
                ['text' => 'In "Placeholders Definition" you can link the campaign to a matter to fill the placeholders from it, and list the extra placeholders you use (name, amount, matter_number). In "Recipients & CC", set a fixed CC/BCC copy for all the campaign\'s messages.'],
                ['text' => 'In "Schedule & Limits", set the "Daily Send Limit" (it protects the mailbox from being blocked), the "Scheduled At" time if you want it to start on its own (leave it empty to start it by hand), and the "Status" (usually Draft). Then click "Create".', 'shot' => 'create-3-done'],
            ],
            'tips' => ['Turn on "Has Attachment" to attach one file to every message.'],
        ],

        [
            'id' => 'recipients',
            'title' => 'Add and import recipients',
            'description' => 'The recipient list is at the bottom of the campaign page, with these columns: email, name, status, sent at and failed at. You can add a recipient by hand or import a whole list from a file. Each recipient\'s status changes from "Pending" to "Sent" or "Failed".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:BulkMailCampaign', 'View:BulkMailCampaignRecipientsRelation'],
            'steps' => [
                ['text' => 'Open the campaign by clicking "View", then scroll down to the "Bulk Mail Recipients" table.', 'shot' => 'view-draft'],
                ['text' => 'Click "New Bulk Mail Recipient" to add one recipient (email, name, CC and placeholder values), or "Import Bulk Mail Recipients" to upload a file with columns for the email, the name and the placeholder values.'],
            ],
            'tips' => ['The icons on each row: preview the message as it will arrive, download PDF, print, resend, edit and delete.'],
        ],

        [
            'id' => 'run',
            'title' => 'Start, pause and follow a campaign',
            'description' => 'Once the recipients are ready, you start the campaign with one button, and the system sends in the background while keeping to the daily limit. Cards at the top of the page show: total, sent, failed, remaining today and the success rate.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:BulkMailCampaign'],
            'steps' => [
                ['text' => 'Test the message first with the "Send Test Email" button, so you receive a copy yourself.', 'shot' => 'view-draft'],
                ['text' => 'Click "Start Campaign" (shown for draft and paused campaigns). The status changes to "Active" and sending begins.', 'shot' => 'view-start'],
                ['text' => 'While sending, a "Pause Campaign" button shows. When it is done, the status becomes "Completed", as in earlier campaigns, with the success rate.', 'shot' => 'view-completed'],
                ['text' => 'To fix failures: select the failed rows and choose "Retry Failed", or use "Resend" for anyone you want.'],
            ],
            'tips' => ['"Download All PDFs" and "Regenerate PDFs" let you save a PDF copy of every message to the recipients.'],
        ],

        [
            'id' => 'import-sent',
            'title' => 'Import emails sent by hand',
            'description' => 'To record emails sent straight from the mailbox (outside the system): the system reads the mailbox\'s Sent folder and creates a "Completed" campaign with one recipient for each message whose subject matches what you type, with its sent date. It does not send anything.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:BulkMailCampaign'],
            'steps' => [
                ['text' => 'Click "Import sent emails".', 'shot' => 'list-2-import'],
                ['text' => 'Enter the "Campaign name", choose "Sent from" (the mailbox), fill in "Subject contains", set "Sent from date" and "Sent to date", then click "Submit".', 'shot' => 'import-1'],
            ],
            'tips' => ['This works when the mailbox is set up for reading (IMAP) in the mail senders settings.'],
        ],
    ],
];
