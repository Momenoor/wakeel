<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Email templates',
    'intro' => 'Ready-made wording for emails, chosen when you send a letter by email or prepare a message you send often (an appointment notice, a fee reminder, a request for documents). You can put "merge tags" in the subject and the text, such as {{matter.reference}}, to be replaced with the matter\'s details. Find them under "Settings → Templates → Email templates".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the email templates',
            'description' => 'The table shows each template\'s name and subject, whether it is "Default" and whether it is "Active". Each template has icons: edit, replicate and delete.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:EmailTemplate'],
            'steps' => [['text' => 'In the side menu, go to "Settings" → "Templates" → "Email templates".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Add an email template',
            'description' => 'You create the template in a small window: the name, the language, the subject and the email text in a rich editor with a "Merge tags" menu, plus two options: "Default" and "Active".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:EmailTemplate'],
            'steps' => [
                ['text' => 'Click "New Email template".', 'shot' => 'create-1'],
                ['text' => 'Enter the "Name" (to recognise it), choose the "Language" and enter the "Subject" (merge tags work here). In the text editor, use the "Merge tags" menu to insert placeholders. "Default" makes it the first choice when sending, and "Active" shows it in the lists.', 'shot' => 'create-2-filled'],
                ['text' => 'Click "Create". The template appears in the table.', 'shot' => 'create-3-done'],
            ],
            'tips' => ['Only one template can be the "Default". You link an email template to a letter template with the "Covering email" field.'],
        ],
        [
            'id' => 'edit',
            'title' => 'Edit, replicate or delete a template',
            'description' => '"Replicate" creates a new matching template for you to edit, which is quicker than starting from scratch.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:EmailTemplate', 'Replicate:EmailTemplate', 'Delete:EmailTemplate'],
            'steps' => [['text' => 'Use the "Edit", "Replicate" and "Delete" icons at the end of the template\'s row, then save your change in the window.']],
        ],
    ],
];
