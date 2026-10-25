<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Letter templates and the items library',
    'intro' => 'A letter template is the wording a letter on a matter is issued from: the office paper, a form of details the staff member fills in when issuing, and a text with {{placeholders}} that are replaced with the matter\'s details. The items library holds ready-made lists (such as required documents) that the staff member ticks from when issuing. Find them under "Settings → Templates → Letter Templates" and "Letter items library".',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'View the letter templates',
            'description' => 'The table shows the name, code, subject, category, language and whether the template is active. The eye icon shows its text, and the pen edits it.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:LetterTemplate'],
            'steps' => [['text' => 'Under "Settings → Templates", choose "Letter Templates".', 'shot' => 'list-1']],
        ],
        [
            'id' => 'create',
            'title' => 'Create a letter template',
            'description' => 'A template has three parts: the template\'s details, "What to fill in when issuing", and the letter\'s text.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:LetterTemplate'],
            'steps' => [
                ['text' => 'Click "New Letter Template". In the "Template" section, set the "Name", the "Code" (a short identifier that must be unique), the "Category" (Letter, Court Notices, Meeting Notices, Appointments, Reports, Meeting minutes, Others), the "Language" (Arabic is written right to left), the "Letterhead" (the office paper used), the "English font" and "Arabic font", the "Covering email" (the default email template when the letter is sent) and "Active".', 'shot' => 'create-1'],
                ['text' => '"Matter types": choose the types the template shows for when a letter is issued on a matter, or leave it empty to show it for all types. Enter the "Subject", which accepts placeholders such as {{matter.number}}.'],
                ['text' => 'Under "What to fill in when issuing", click "Add field": the "Label" (what the staff member sees), the "Key" (used in the text as {{input.KEY}}), the "Type" (Text, Long text, Date, Time, Link, Number, Choice, On / off switch, Items from the library), and whether it is "Required".'],
                ['text' => 'Write the letter\'s text in the editor, and use the { } menu to insert placeholders: {{recipients}} (the addressee block, on a line of its own), {{input.KEY}}, {{signature}} and {{stamp}} (the signature and stamp images from the letterhead). Then click "Create".'],
            ],
            'tips' => [
                'For "Meeting minutes" templates, extra sections appear: the opening and closing paragraphs, and the layout of the "Attendees list" (grouped, one numbered list, or a table) and its columns. The capacity heading can name the party\'s group: {{attendee.group}} is the capacity of the party the attendee represents (the claimant, for example) and {{attendee.party}} is its name, so the party and those representing it come under one heading ("Grouped by party, under each capacity").',
                'The editor (in letter templates, letters, minutes, email templates, bulk email and mailbox signatures) supports fonts, sizes, line spacing, colours, indents and the direction of each paragraph, tables (drag columns and rows, cell borders, shading and merging), page breaks and images, and cleaning up text pasted from Word. A table without borders shows without lines in PDF and Word. The letter in PDF and Word keeps all of this.',
                'Insert placeholders from their menu or by typing {{. The formatting around them (bold, size, colour) stays in the letter. Double-clicking a placeholder selects it.',
                'A template with the "Employee certificates" category is used for certificates in "Employee requests" and does not show in matter letters. It has the employee\'s placeholders ({{employee.name}}, job title, nationality, joining date, passport, salary and its total in words) and the office\'s placeholders ({{company.name}}), each in the template\'s language, or in a given one by adding _ar / _en.',
                'After a template is edited, the general manager can rebuild a letter or minutes issued from it from the edited template, keeping its details, and undo that.',
            ],
        ],
        [
            'id' => 'edit-preview',
            'title' => 'Edit and preview a template',
            'description' => 'Editing does not change letters already issued: each letter\'s text is kept as it was issued.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:LetterTemplate', 'Delete:LetterTemplate'],
            'steps' => [
                ['text' => 'Click the pen icon on the template\'s row. At the top of the page: "Preview PDF" to see the letter in its final form on the office paper, plus "View" and "Delete".', 'shot' => 'edit-1'],
                ['text' => 'The "View" page shows the text as it will be issued, before any change.', 'shot' => 'view-1'],
            ],
        ],
        [
            'id' => 'items',
            'title' => 'Letter items library',
            'description' => 'Lists of ready-made items, grouped (such as "Required documents" and "Inspection items"), used in a field of the "Items from the library" type in a letter template, so the staff member ticks what is needed when issuing. An item can be limited to certain matter types.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:LetterItem', 'Create:LetterItem', 'Update:LetterItem', 'Delete:LetterItem'],
            'steps' => [
                ['text' => 'Under "Settings → Templates", choose "Letter items library". The items are grouped under a "Group: …" heading, and each item has an "Active" switch and edit, replicate and delete icons.', 'shot' => 'letteritems/list-1'],
                ['text' => 'Click "New Letter item": enter the "Group" (a new or existing list name), choose the "Matter types" or leave it to show for all types, enter the "Text", then click "Create".', 'shot' => 'letteritems/create-1'],
                ['text' => 'To change the order of the items, click "Reorder records" and drag the items.', 'shot' => 'letteritems/reorder-1'],
            ],
        ],
    ],
];
