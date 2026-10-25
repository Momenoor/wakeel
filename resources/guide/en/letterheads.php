<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Letterheads, signature blocks and fonts',
    'intro' => 'These tools set the look of the office\'s official papers: the letterhead (the page image, margins, watermark, and where the reference number, date and logo go), the signature block (the expert\'s and the assistant\'s signature and stamp), and the fonts letters are printed in. Find them under "Settings → Templates".',
    'actions' => [
        [
            'id' => 'letterhead-create',
            'title' => 'Create a letterhead',
            'description' => 'The letterhead is the office paper that every letter or minutes is printed on. You set its image and margins, then arrange its elements in the designer.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Letterhead'],
            'steps' => [
                ['text' => 'Under "Settings → Templates" → "Letterheads", click "New Letterhead".', 'shot' => 'list-1'],
                ['text' => 'Enter the "Name" and choose the "Orientation" (portrait or landscape). Turn on "Default letterhead" to use it with any template that has no letterhead of its own. Under "Letterhead images", upload the paper image for the first page and for the following pages (A4 size).', 'shot' => 'create-1'],
                ['text' => 'Set the "Margins (mm)" for the first page and for the other pages, and the "Watermark" (none, text, or an image with its opacity). Upload the "Signature and stamp" images and set their height, then click "Create".'],
            ],
        ],
        [
            'id' => 'letterhead-design',
            'title' => 'Design the letterhead (element positions)',
            'description' => 'The designer shows the page and lets you place elements that appear on every letter: reference number, date, text box, logo, image, line and page number.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Letterhead'],
            'steps' => [
                ['text' => 'Click "Design" on the letterhead\'s row. The page shows on the left, with the "Add element" and "Elements" panels on the right.', 'shot' => 'design-1'],
                ['text' => 'Click an element type to add it, drag it into place on the page, and change its properties (font, size, alignment and colour). The "Preview PDF" button shows you the result, and "Save" keeps the design. The "Images & margins" button takes you back to the letterhead\'s details.'],
            ],
            'tips' => ['Newer elements are drawn on top of older ones. For each element, choose whether it shows on the first page or on every page.'],
        ],
        [
            'id' => 'signature',
            'title' => 'Signature blocks',
            'description' => 'A signature block is a small box of arranged elements (signature, stamp, image, text). You insert it at the end of a letter or minutes from the blocks menu in the template editor. Its layout is saved with the letter, so it does not change later.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:SignatureLayout', 'Update:SignatureLayout'],
            'steps' => [
                ['text' => 'Under "Settings → Templates" → "Signature blocks", click "New Signature block". Enter the "Name", the "Width (mm)" and the "Height (mm)", and its alignment (right, centre or left).', 'shot' => 'signatures/create-1'],
                ['text' => 'Click "Design" to arrange the elements: "Signature" and "Stamp" (from the chosen letterhead\'s images), "Image" and "Text". Then click "Save".', 'shot' => 'signatures/design-1'],
            ],
            'tips' => ['"Copy" creates a new block matching an existing one.'],
        ],
        [
            'id' => 'fonts',
            'title' => 'Fonts',
            'description' => 'Upload a font to print letters in it instead of the default font. You choose it in the letter template (the English font and the Arabic font). The "Word documents font" is set by its name, for Word files.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:LetterFont', 'Update:LetterFont', 'Delete:LetterFont'],
            'steps' => [
                ['text' => 'Under "Settings → Templates" → "Fonts", you see the fonts already added (or "No Fonts").', 'shot' => 'fonts/list-1'],
                ['text' => 'Click "New Font", enter the "Name" and upload the font files (regular, bold and italic if you have them), then click "Create".', 'shot' => 'fonts/create-1'],
            ],
        ],
    ],
];
