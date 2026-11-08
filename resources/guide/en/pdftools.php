<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'PDF tools',
    'intro' => 'The "PDF tools" group in the side menu: "Sign PDF" puts a signature, stamp, date or text on a file and edits its text, "Merge PDF" puts several files (or a whole folder) into one, "Compress PDF" makes a file smaller, "Split PDF" splits a large file into parts — each on its own, or one after another (merge, then compress, then split) — and "Signatures & stamps" is the library of your signatures and the office\'s stamps. The file is handled in your browser and is not uploaded to the server. Only a note of what you did with it is kept.',
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
            'description' => 'A file from your computer or from your OneDrive ("From OneDrive", with search) is split in the browser "By size" (the most megabytes per part) or "By pages" (the number of pages per part) or "Part by part" (you say where each part starts and ends: "Add a part" starts the next one on the page after the last, running to the last page until you change it). Download all the parts in one zip file, with a list of each part\'s pages and size, or each part on its own. Nothing downloads before you click the button. A page that is larger than the limit on its own is noted in a part of its own, and links to other pages are cut so the part does not grow. Both fields accept digits from any keyboard (Arabic and Persian, and any decimal separator).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Use:SplitPdf'],
            'steps' => [
                ['text' => 'Under "PDF tools" → "Split PDF", click "Choose a PDF" or "From OneDrive". The largest size allowed shows under the buttons.', 'shot' => 'split-0'],
                ['text' => 'Choose "How to split" and enter the number, then click "Split". The result shows in words (the number of parts and their sizes). Then click "Download all (zip)", or "Download" for each part.', 'shot' => 'split-1'],
            ],
            'tips' => ['Very large files are best split on a computer: a phone may run out of memory.', '"Discard" beside the chosen file puts it aside, so you can choose another.'],
        ],
        [
            'id' => 'merge',
            'title' => 'Merge PDFs',
            'description' => 'Several PDFs put together into one, in the browser: chosen one by one, or a whole folder — every PDF in it, in the order of their names (1, 2, … 10) — from your computer or your OneDrive. Change the order before merging, and each file can get a bookmark at its first page. Nothing is uploaded.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Use:MergePdf'],
            'steps' => [
                ['text' => 'Under "PDF tools" → "Merge PDF", click "Choose PDFs" (several at once), "Choose a folder" (every PDF in it and in its sub-folders), or "From OneDrive" — where you can pick PDFs one by one, or open a folder and click "Add every PDF in this folder".'],
                ['text' => 'The files are listed in the order they will be merged. Move one with the up and down arrows, take one out with ×, or click "Sort by name". "Discard all" empties the list.'],
                ['text' => 'Give the merged file a "Name", leave "A bookmark for each file" on or turn it off, then click "Merge". Then click "Download", or carry on: "Then compress it" or "Then split it" opens that tool with the merged PDF already chosen.'],
            ],
            'tips' => ['To merge, compress and split in one go: merge, click "Then compress it", compress, then click "Then split it".'],
        ],
        [
            'id' => 'compress',
            'title' => 'Compress a PDF',
            'description' => 'A PDF made smaller in the browser, for a portal\'s upload limit or an email: its scanned pages and pictures of every kind (JPEG, JPEG 2000, colour for print, pages scanned in strips …) are saved again at a lower resolution, while its text stays sharp and searchable. What is in the file twice (the same fonts and pictures in every part of a merged file) is kept once, what is left over from earlier edits and never shown is dropped, and its text and drawings are packed tighter. "Light" barely changes how it looks, "Medium" (about 130 dpi) usually suits court papers, "Strong" (about 100 dpi) makes a much smaller file, and "Maximum" (about 75 dpi) the smallest, though small print may blur. Several pictures are made smaller at once, one on each of the computer\'s processor cores. A PDF that cannot be made smaller (one with few or small pictures) is given back as it was.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Use:CompressPdf'],
            'steps' => [
                ['text' => 'Under "PDF tools" → "Compress PDF", click "Choose a PDF" or "From OneDrive" (or come from Merge PDF with "Then compress it"). "Discard" puts the file aside to choose another.'],
                ['text' => 'Choose "How much to compress", then click "Compress". The result says the size before and after.'],
                ['text' => 'Check it under "Preview": each page "Before compressing" and "After compressing" side by side. Go to a page with the arrows or by typing its number, and choose a "Zoom" (150% to 300%) to see whether small print is still sharp: zoomed in, both scroll together.'],
                ['text' => 'Click "Download", or "Then split it" to split the smaller file. Not sharp enough? Choose a lighter level and click "Compress" again.'],
            ],
            'tips' => ['A PDF protected with a password cannot be compressed or merged here.'],
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
