<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'PDF tools',
    'intro' => 'The "PDF tools" group in the side menu: "Sign PDF" puts a signature, stamp, date or text on a file and edits its text, "Split PDF" splits a large file into parts, and "Signatures & stamps" is the library of your signatures and the office\'s stamps. The file is handled in your browser and is not uploaded to the server. Only a note that you signed or split it is kept.',
    'actions' => [
        [
            'id' => 'sign',
            'title' => 'Sign and stamp a PDF',
            'description' => 'Choose a file from your computer (or open it from "Sign & stamp" in the matter\'s files). Then, from the "Signatures & stamps" panel, put on it a signature or stamp from your library or the office\'s, or use "Draw now", "Date" or "Text". Drag a placed item to move it. The corner handle resizes it and the round handle turns it, and you can "Put on every page". Then "Download" the signed file.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Use:SignPdf', 'UseShared:SignatureStamp (for the office\'s signatures and stamps)'],
            'steps' => [
                ['text' => 'In the side menu, go to "PDF tools" → "Sign PDF". Click "Choose a PDF" or drop the file on the page.', 'shot' => 'sign-0'],
                ['text' => 'Click a signature or stamp in the "Signatures & stamps" panel, then click where it goes on the page, or drag it onto the page. "Date" and "Text" put text you type, in its colour and size. "Manage" opens your library. Then click "Download".', 'shot' => 'sign-1'],
            ],
            'tips' => ['A password-protected file cannot be signed here.'],
        ],
        [
            'id' => 'sign-file',
            'title' => 'Sign a file from the matter\'s files',
            'description' => 'Every PDF in the matter\'s "Files" tab (and in fee and payment attachments) has a "Sign & stamp" icon that opens it straight in "Sign PDF". After signing, the "Save signed copy" button saves the signed file in the matter\'s files.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Use:SignPdf'],
            'steps' => [
                ['text' => 'In the "Files" tab, click the "Sign & stamp" icon (the pen) on the file\'s row, put the signature or stamp, then click "Save signed copy".', 'shot' => 'sign-file'],
            ],
        ],
        [
            'id' => 'edit-text',
            'title' => 'Edit the text of a PDF',
            'description' => 'The "Edit the PDF\'s text" button shows the file\'s lines of text, so you can click any line and edit it. The new text is written in the line\'s own font, size and colours, taken from the file itself ("Font found"). The old line is covered and the new one is written on top: it is not seen or printed, but copying or searching the file may still find the old text.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Use:SignPdf'],
            'steps' => [
                ['text' => 'After opening the file, click "Edit the PDF\'s text": the lines of text are outlined. Click a line (the date, for example) and edit it, then click "Finish editing" and "Download".', 'shot' => 'sign-2-edit-text'],
            ],
            'tips' => ['Do not use it to change an official document issued by someone else.'],
        ],
        [
            'id' => 'split',
            'title' => 'Split a PDF',
            'description' => 'A file from your computer or from your OneDrive ("From OneDrive", with search) is split in the browser "By size" (the most megabytes per part) or "By pages" (the number of pages per part). Download all the parts in one zip file, with a list of each part\'s pages and size, or each part on its own. Nothing downloads before you click the button. A page that is larger than the limit on its own is noted in a part of its own, and links to other pages are cut so the part does not grow. Both fields accept digits from any keyboard (Arabic and Persian, and any decimal separator).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Use:SplitPdf'],
            'steps' => [
                ['text' => 'Under "PDF tools" → "Split PDF", click "Choose a PDF" or "From OneDrive". The largest size allowed shows under the buttons.', 'shot' => 'split-0'],
                ['text' => 'Choose "How to split" and enter the number, then click "Split". The result shows in words (the number of parts and their sizes). Then click "Download all (zip)", or "Download" for each part.', 'shot' => 'split-1'],
            ],
            'tips' => ['Very large files are best split on a computer: a phone may run out of memory.'],
        ],
        [
            'id' => 'stamps',
            'title' => 'The signatures and stamps library',
            'description' => 'Each user has their own signatures and stamps, and the office has shared ones that anyone allowed can put on a file. Each item has a "Name", a "Kind" (Signature / Stamp), a "Width on the page" (in millimetres when first placed) and "The image": "Upload a file" (PNG, JPG or WEBP up to 2 MB; a PNG with a transparent background is best) or "Draw it" with a finger, a pen or the mouse. "The office\'s (shared)" is for those who manage the office\'s signatures.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Use:SignPdf', 'ManageShared:SignatureStamp (for the office\'s signatures and stamps)'],
            'steps' => [
                ['text' => 'Go to "PDF tools" → "Signatures & stamps". "Import from letterhead" copies each letterhead\'s signature and stamp here as the office\'s signatures and stamps.', 'shot' => 'stamps-1'],
                ['text' => 'Click "New Signature or stamp", fill in the fields and upload or draw the image, then click "Create".', 'shot' => 'stamps-2-create'],
            ],
            'tips' => ['A drawing made with "Draw now" on the signing page can be kept with "Save to my library" to use later.'],
        ],
    ],
];
