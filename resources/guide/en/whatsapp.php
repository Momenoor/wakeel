<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'WhatsApp templates',
    'intro' => 'The system sends WhatsApp messages through Meta\'s WhatsApp Business API, which only accepts templates it has approved in advance. Here you record the approved templates and link their parameters to the system\'s data (such as the matter number). From here, meeting minutes are sent for signature and the recipient\'s reply is received. Find them under "Settings → Templates → WhatsApp templates".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the templates',
            'description' => 'The table shows the name, the purpose (minutes for signature, reminder, and so on), the template name in Meta, the language, and whether it is default and active.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:WhatsAppTemplate'],
            'steps' => [['text' => 'Under "Settings → Templates", choose "WhatsApp templates".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Add a WhatsApp template',
            'description' => 'The template here matches a template Meta has already approved, with the same name and language code. Its text must match the approved text exactly.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:WhatsAppTemplate'],
            'steps' => [
                ['text' => 'Click "New WhatsApp template" and enter the "Name", "Used for" (the template\'s purpose), "Template name in Meta" and "Language code" (such as ar).', 'shot' => 'create-1'],
                ['text' => 'Choose the "Header" (attach the minutes as a PDF, or none), and write the "Message text" with the numbered parameters {{1}} {{2}}. Then define each "Parameter" and what it is "Filled with" (fields such as matter.reference). In "Reply when the signed copy arrives", write the automatic reply to the recipient. Then click "Create".'],
            ],
            'tips' => [
                'Turn on "Default" to make it the usual template for its purpose, and "Active" to use it.',
                '"Used for" says what the template carries: minutes for signature, a report for review, a notification (the bell\'s title, text and link, for whoever turned WhatsApp on in their notification preferences: its parameters may use {{user}}, {{title}}, {{body}} and {{link}}), or a notification with a file — the same with the document header and {{file}}, used when a notification carries files (a request\'s decision sent back to the assistant: one message a file). Without it, the files follow the notification as plain files, which Meta delivers only within 24 hours of the recipient\'s last message to the office\'s number.',
            ],
        ],
        [
            'id' => 'webhook',
            'title' => 'Set up receiving replies (Webhook)',
            'description' => 'For the system to receive recipients\' replies and signed copies, enter the callback URL and verify token that the system shows into your Meta app settings, and save the "App secret" here.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:WhatsAppTemplate'],
            'steps' => [
                ['text' => 'Click "Replies (webhook)". Copy the "Callback URL" and the "Verify token" into the WhatsApp settings in the Meta developer dashboard, enter the "App secret", then click "Save".', 'shot' => 'webhook-1'],
            ],
            'tips' => ['Sending messages also needs the WHATSAPP_TOKEN and WHATSAPP_PHONE_ID variables in the environment file (.env).'],
        ],
    ],
];
