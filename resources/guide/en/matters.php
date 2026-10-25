<?php

/**
 * Who can do what: "Super admin" (super-admin) passes every check; any other
 * role needs the named permission, granted from Users → Roles (Shield).
 */
$admin = 'Super admin (super-admin), always';
$withPermission = 'Any role with the permission named';

return [
    'title' => 'Matters',
    'intro' => 'The matter is the main file in the system: it brings together the court, the type, the parties, the experts, the fees and collections, the requests, notes, attachments, letters and minutes. Find this section in the side menu → "Matters". Each action below is written as steps with a picture for each step, and under it the roles that can do it. The pictures are taken from demo data.',
    'actions' => [

        [
            'id' => 'list',
            'title' => 'View the list of matters and its tabs',
            'description' => 'The list shows every matter with its court, type, level, parties, experts, fees and next session date. The tabs at the top split the matters by stage: All, In Progress, Initial Prepared, Final Submitted and Deleted. The small number on each tab is how many matters it holds.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Matter', 'View:MattersAllTab', 'View:MattersInProgressTab', 'View:MattersInitialPreparedTab', 'View:MattersFinalSubmittedTab', 'View:MattersDeletedTab'],
            'steps' => [
                ['text' => 'In the side menu, choose "Matters". The list opens on the "In Progress" tab.', 'shot' => 'list-1'],
                ['text' => 'The tabs above the table separate the matters by stage. Each user sees only the tabs their role allows.', 'shot' => 'list-tabs'],
                ['text' => 'Click "Initial Prepared", for example, to see the matters whose initial report was submitted but whose final report has not been delivered.', 'shot' => 'list-tab-initial'],
                ['text' => 'At the end of each row are three icons: the eye to view the matter, the pencil to edit it, and the bin to delete it.', 'shot' => 'list-row-actions'],
            ],
            'tips' => ['The number of matters on the tabs updates by itself after every action.'],
        ],

        [
            'id' => 'search-filter',
            'title' => 'Search, filter and show columns',
            'description' => 'To reach a matter quickly: search by its number or by the name of a party or expert, narrow the list with the filters, or choose the columns you want to see.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Matter'],
            'steps' => [
                ['text' => 'Type the matter number or a name in the "Search" box. The results update straight away.', 'shot' => 'list-search'],
                ['text' => 'Click the filter (funnel) icon to open the filters: status, type, court, level, session and report dates, and collection status. Click "Apply filters".', 'shot' => 'list-filters'],
                ['text' => 'The columns icon lets you show or hide table columns, such as the initial report date, the creation date and the notes.', 'shot' => 'list-columns'],
            ],
            'tips' => [
                'To clear all the filters, click "Reset", or the × next to the filter shown.',
                'To search from any page, use "Search" in the top bar (Ctrl+K). It takes the matter number in any form (7777, 7777/2026, 2026/7777 or with a backslash), and a party\'s phone number or email. See "Quick start → Global search".',
                'Wherever a matter is picked or searched for (filing an email, moving it, events, search), its level (original or supplementary), stage and status show with its number, so you can tell similar matters apart.',
            ],
        ],

        [
            'id' => 'create-matter',
            'title' => 'Add a new matter',
            'description' => 'You record a matter when the court\'s appointment decision arrives. The form gathers the matter\'s basic details, the court, the type and the level, the appointed experts, the parties, and the fees if they are known.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Matter'],
            'steps' => [
                ['text' => 'In the matters list, click the blue "New Matter" button.', 'shot' => 'create-0'],
                ['text' => 'The form opens. In one column: "Basic Data" and "Court Data". In the other: "Parties & Experts" and "Fees".', 'shot' => 'create-1'],
                ['text' => 'Fill in the year, the number, the date received from the court, the assistant\'s assignment date and the next session date. Then choose the court, the level, the matter type, the difficulty and the appointment decision (single or committee).', 'shot' => 'create-2-basic'],
                ['text' => 'Add the certified expert, the parties (plaintiff and defendant) and a fee row if there is one. Fields marked with an asterisk (*) are required.', 'shot' => 'create-3-parties'],
                ['text' => 'Click "Create". A "Created" message appears, then the new matter\'s page opens.', 'shot' => 'create-4-done'],
            ],
            'tips' => [
                'To add several matters one after another, click "Create & create another" instead of "Create".',
                'If a party or expert is not in the list, add them from the + icon next to the field without leaving the form.',
                'When the matter is added, the assistants assigned to it get a notification.',
            ],
        ],

        [
            'id' => 'view-matter',
            'title' => 'View a matter and its tabs',
            'description' => 'The matter\'s page sums everything up: the top bar shows the status, court, type, next session, last step and assistants. Below it are tabs: Overview, Progress, Sessions & Events, Fees & Incentive, Requests & Notes, Files, Letters, Meeting minutes, Bulk mail, Emails, Contacts and History (the last). Each user sees the tabs their role allows. The lists of requests, fees and notes are sorted newest first.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Matter', 'View:MatterOverviewTab', 'View:MatterProgressTab', 'View:MatterSessionsTab', 'View:MatterFeesTab', 'View:MatterRequestsTab', 'View:MatterFilesTab', 'View:MatterLettersTab', 'View:MatterMinutesTab', 'View:MatterBulkMailTab', 'View:MatterEmailsTab', 'View:MatterContactsTab', 'View:MatterHistoryTab'],
            'steps' => [
                ['text' => 'In the matters list, click the eye icon on the matter\'s row, or click its number.', 'shot' => 'view-1'],
                ['text' => 'The tab bar is below the summary. The open tab is kept in the page address (such as ?tab=fees), so refreshing the page or sharing its link opens the same tab.', 'shot' => 'view-tabs'],
                ['text' => 'The action buttons at the top of the page: sync with Outlook, "Submit Initial Report", "Submit Final Report", "Supplementary Matter", "Edit", "Delete". You see the ones your role allows.', 'shot' => 'view-actions'],
                ['text' => 'The "Sessions & Events" tab shows the upcoming and past events linked to the matter from the calendar.', 'shot' => 'view-sessions'],
                ['text' => 'The "Fees & Incentive" tab shows the fees, the payment history and the incentive calculation.', 'shot' => 'view-fees'],
                ['text' => 'The "Requests & Notes" tab shows the requests made on the matter and the team\'s notes.', 'shot' => 'view-requests-notes'],
                ['text' => 'The "Files" tab shows the attachments. The "Letters" and "Meeting minutes" tabs show the correspondence and the meeting minutes.', 'shot' => 'view-files'],
            ],
        ],

        [
            'id' => 'edit-matter',
            'title' => 'Edit a matter',
            'description' => 'To correct a matter\'s details or update its parties, experts and fees.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Matter'],
            'steps' => [
                ['text' => 'Open the matter and click the "Edit" button at the top of the page (or the pencil icon in the list).', 'shot' => 'edit-0'],
                ['text' => 'The same form as for adding opens, filled with the matter\'s details. Extra fields appear for those allowed, such as the initial and final report dates and the number of reviews.', 'shot' => 'edit-1'],
                ['text' => 'Change what you need, for example the difficulty.', 'shot' => 'edit-2'],
                ['text' => 'Click "Save changes". A "Saved" message appears and you return to the matter\'s page.', 'shot' => 'edit-3'],
            ],
            'tips' => ['Changing the difficulty or the distributed date after delivery may need a "request" approved by the person in charge. See the request actions below.'],
        ],

        [
            'id' => 'initial-report',
            'title' => 'Submit the initial report',
            'description' => 'You record that a matter\'s initial report was submitted by attaching a screenshot or file proving it was submitted on the court\'s system. The matter then moves to the "Initial Prepared" tab.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['InitialReport:Matter', 'UpdateInitialReportDate:Matter (to set a different date)'],
            'steps' => [
                ['text' => 'Open a matter in progress and click "Submit Initial Report".', 'shot' => 'initial-0'],
                ['text' => 'A window asks for a "Screenshot" (a picture or PDF), and an optional date for anyone allowed to change the date.', 'shot' => 'initial-1'],
                ['text' => 'Drag the file into the box or click "Browse" to choose it. Wait for the upload to finish, then click "Confirm".', 'shot' => 'initial-2'],
                ['text' => 'An "Initial Report Submitted" message appears, and the button turns grey and reads "Initial Report Submitted".', 'shot' => 'initial-3'],
            ],
            'tips' => ['The action cannot be repeated: after submitting, the button is disabled.'],
        ],

        [
            'id' => 'final-report',
            'title' => 'Submit the final report',
            'description' => 'The final report is submitted after the initial report. The button stays disabled until the report is approved through a "Confirm Report" request (the memo approval date is recorded).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['FinalReport:Matter', 'UpdateFinalReportDate:Matter (to set a different date)'],
            'steps' => [
                ['text' => 'Open a matter whose initial report was submitted. The "Submit Final Report" button is green but disabled if the report has not been approved.', 'shot' => 'final-0'],
                ['text' => 'Hover over the disabled button to see "Final Report must be approved first.". Create a "Confirm Report" request for the person in charge to approve, and the button is enabled.', 'shot' => 'final-1'],
            ],
            'tips' => ['After submitting, the matter moves to the "Final Submitted" tab and the incentive starts to be counted according to the matter type\'s settings.'],
        ],

        [
            'id' => 'supplementary',
            'title' => 'Create a supplementary matter',
            'description' => 'The system copies the current matter into a new matter linked to it (the parent matter) with the same parties and experts, new dates and numbers, and the status and fees reset.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Replicate:Matter'],
            'steps' => [
                ['text' => 'Open the original matter and click "Supplementary Matter".', 'shot' => 'supp-1'],
                ['text' => 'Edit the number, year, received date, distributed date, next session date and level, then click "Submit". A "Supplementary Matter cloned successfully" message appears and you move to the new matter.', 'shot' => 'supp-2'],
            ],
            'tips' => ['The parent matter shows in the "Parent Matter" field in the Overview.'],
        ],

        [
            'id' => 'add-fee',
            'title' => 'Add fees to a matter',
            'description' => 'Records the fees set on the matter, of every type: expert fee, marketing, court penalty, office share, discount and others. Each fee carries its own VAT: its basic amount, its VAT and its total, at the rate set in "Financial Settings → VAT" (5%). So the "VAT" type no longer shows when you add a fee, and no separate VAT row is added. Discounts are stored with a minus sign for you.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['CreateFee:Matter', 'UpdateFee:Matter (to edit)', 'DeleteFee:Matter (to delete)'],
            'steps' => [
                ['text' => 'Open the matter, go to the "Fees & Incentive" tab, then click "Add Fee".', 'shot' => 'fees-0'],
                ['text' => 'Choose the "Type" and type the "Amount". The "Including VAT" switch is on by default for expert fees: the amount you type is the total, as on the invoice. If you turn it off, the amount is the basic and the VAT is added to it. The line under the switch shows "Basic · VAT · Total" as you typed it (10,500 including = 10,000 + 500). You can attach the invoice or contract in "Attachments" and choose the "Attachment category", then click "Submit".', 'shot' => 'fees-1-add'],
                ['text' => 'The fee appears in the list with its status and the amount collected. At the top of the tab are the matter\'s "Basic amount", "VAT amount" and "Total amount", and under each fee its total and a "Basic · VAT" line.', 'shot' => 'fees-vat'],
            ],
            'tips' => [
                'To edit or delete a fee, use the pencil and bin icons next to it. The "Edit Fee" window has the same switch. The paperclip icon attaches files to it later, and its attachments show under it (a PDF or picture opens in the browser).',
                'Fees recorded before this update stay as they were (their VAT is 0). The management merges the old VAT rows into their fees from "Fee Data Maintenance".',
                'Adding, collecting, editing or deleting fees is reported to those set in "Notification preferences" (the super admin and the accountants to begin with).',
            ],
        ],

        [
            'id' => 'collect-fee',
            'title' => 'Collect a payment on a fee',
            'description' => 'To record an amount the office received from the client for the matter\'s fees. What is collected is measured against the fee\'s total (including VAT), and the collection status updates by itself: "Unpaid", "Partial", "Paid" or "Overpaid". What is collected and the total are compared after each is rounded to 2 decimals. A difference of less than 1 dirham either way (rounding differences) counts as "Paid". Short by 1 dirham or more is "Partial", and over by 1 dirham or more is "Overpaid". The matter\'s collection status is read the same way.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['CollectFee:Matter', 'UpdateAllocation:Matter (edit a payment)', 'DeleteAllocation:Matter (delete a payment)'],
            'steps' => [
                ['text' => 'In the "Fees & Incentive" tab, click the green "Collect Fee" button next to the fee.', 'shot' => 'fees-2-added'],
                ['text' => 'The window shows the fee\'s total, "Collected so far" and the "Remaining balance". Type the "Amount to Collect" (no more than the "Max allowed"), the "Payment Date" and "Notes / Reference", such as the cheque or transfer number. Attach the receipt, transfer or cheque in "Attachments" with the "Attachment category", then click "Submit".', 'shot' => 'fees-3-collect'],
                ['text' => 'A "Collection recorded successfully." message appears, the payment is added to the "Payment History" under the fee with its attached "Receipts", and the collection status changes.', 'shot' => 'fees-4-collected'],
            ],
            'tips' => [
                'You can edit or delete a payment from its icons in the payment history, and attach a receipt later from the paperclip icon.',
                'An attached invoice or receipt that is deleted is only hidden, never lost, because it is financial evidence.',
            ],
        ],

        [
            'id' => 'notes',
            'title' => 'Add a note to a matter',
            'description' => 'Notes record what matters to the team about the matter, with the writer\'s name and the date.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['CreateNote:Matter', 'UpdateNote:Matter', 'DeleteNote:Matter'],
            'steps' => [
                ['text' => 'Open the matter, go to the "Requests & Notes" tab and click "Add Note".', 'shot' => 'rn-0'],
                ['text' => 'Type the note in the "Content" field, then click "Submit".', 'shot' => 'note-1'],
                ['text' => 'The note appears in the "Notes" section with the writer\'s name and the date, and the edit and delete icons next to it.', 'shot' => 'note-2'],
            ],
        ],

        [
            'id' => 'create-request',
            'title' => 'Make a request on a matter',
            'description' => 'Requests go through an approval cycle: the assistant makes them and the person in charge reviews them. The types: "Change Difficulty", "Change Distributed Date", "Confirm Office Work", "Review Report", "Confirm Report" and "Review Incentive".',
            'roles' => [$admin, $withPermission],
            'permissions' => ['CreateRequest:Matter'],
            'steps' => [
                ['text' => 'In the "Requests & Notes" tab, click "Add Request".', 'shot' => 'req-0'],
                ['text' => 'Choose the "Request Type".', 'shot' => 'req-1'],
                ['text' => 'The fields for that type appear (such as "New Difficulty" to change the difficulty). Write a "Comment" explaining why, add an attachment if needed, then click "Submit".', 'shot' => 'req-2'],
            ],
            'tips' => ['The person in charge gets a notification in the system and an email with a signed link to review it.'],
        ],

        [
            'id' => 'review-request',
            'title' => 'Approve or reject a request',
            'description' => 'The person in charge reviews the pending requests. Approving applies the change asked for to the matter straight away. Rejecting needs a comment explaining why.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ApproveRequest:Matter', 'RejectRequest:Matter'],
            'steps' => [
                ['text' => 'On the matter, under the pending request, are two buttons: "Approve" (green) and "Reject" (red).', 'shot' => 'req-3-pending'],
                ['text' => 'When you click "Reject", a window asks for the "Reviewer Comment", then "Confirm". Approving has a similar window.', 'shot' => 'req-4-reject'],
                ['text' => 'You can also review all requests from the side menu → "Requests".', 'shot' => 'reqpage-1'],
                ['text' => 'The request\'s page shows its details, comments and attachments, and the approve and reject buttons.', 'shot' => 'reqpage-2-view'],
            ],
            'tips' => [
                'Each request has a "History": request made, changes asked for, made again, approved or rejected, with the "Step", "By", "Date" and "Note" of each step. It shows on the request\'s page and under the request on the matter, and covers older requests too.',
                'A report sent for review on WhatsApp (to the report reviewers set on the assistant\'s page): when it is approved from WhatsApp, an optional comment is asked for (text, pictures or files), then "Approve" a second time to settle it. The comment and its files are kept with the request and the assistant is told.',
            ],
        ],

        [
            'id' => 'attachments',
            'title' => 'Add attachments',
            'description' => 'The matter\'s files: reports, supporting documents, correspondence, pictures and so on. Each attachment has a type and is shown with its size, date and who uploaded it.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['CreateAttachment:Matter', 'DeleteAttachment:Matter'],
            'steps' => [
                ['text' => 'Open the "Files" tab to see the current attachments with the download and delete icons.', 'shot' => 'files-0'],
                ['text' => 'Click "Add Attachments", drag the files or click "Browse", and choose the "Category" (initial report, final report, supporting document, correspondence, signed minutes, other). Click "Add Another File" to upload more than one file, then click "Submit".', 'shot' => 'files-1'],
            ],
            'tips' => [
                'The largest file allowed is 50 MB.',
                'A PDF has a "Sign & stamp" icon that opens it in "Sign PDF", to put your signature or the office stamp on it and save a signed copy (see the "PDF tools" section).',
                'When keeping files in Azure is turned on, a "Kept in" column (Azure or this server) and a filter of the same name appear.',
            ],
        ],

        [
            'id' => 'sessions',
            'title' => 'Sessions and events, and linking them to the calendar',
            'description' => 'The matter shows the sessions and events linked to it from the system\'s calendar. An event is linked to the matter by itself if its title mentions the matter\'s number, or by hand with the "Link event" button.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MatterSessionsTab', 'Update:Matter (to link an event)'],
            'steps' => [
                ['text' => 'Open the "Sessions & Events" tab and click "Link event".', 'shot' => 'sessions-1'],
                ['text' => 'Choose the event from the list, then click "Submit". The event appears among the matter\'s events.', 'shot' => 'sessions-2'],
            ],
        ],

        [
            'id' => 'letters',
            'title' => 'Issue and send letters',
            'description' => 'You issue official letters on the office\'s letterhead from ready templates (notifying the parties, asking for documents, asking the court for more time…) or write a free letter. Each letter gets a reference number by itself in the form (code/year/matter number/sequence), and its wording is kept as it was issued: it does not change if the template is edited later.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MatterLettersRelation', 'Update:Matter (to issue, edit and delete)'],
            'steps' => [
                ['text' => 'Open the "Letters" tab: the table shows the reference, date, subject, recipient and status, with the "Issue letter" and "Write a letter" buttons.', 'shot' => 'letters-0'],
                ['text' => 'To issue a letter from a template, click "Issue letter". Choose the "Template", the letter\'s date and the letterhead.', 'shot' => 'letters-1-issue'],
                ['text' => 'After you choose the template, its own fields appear, such as "آخر موعد للتسليم" (the deadline). Choose the "Recipients" from the matter\'s parties and their representatives, or add another recipient, and write "For the attention of" if you want.', 'shot' => 'letters-5-issue-filled'],
                ['text' => 'Tick the recipients and fill in the fields, then click "Issue".', 'shot' => 'letters-6-issue-ready'],
                ['text' => 'The new letter appears with its reference number at the top of the table, with a notification holding the PDF and Word links.', 'shot' => 'letters-7-issued'],
                ['text' => 'To write a free letter without a template, click "Write a letter", type the subject, choose the language (Arabic or English) and the recipient, then type the text in the editor.', 'shot' => 'letters-2-write'],
                ['text' => 'The "Preview" button opens the letter as a PDF, as it will print, with the "Open to print", "Word", "Edit" and "Send by email" buttons.', 'shot' => 'letters-3-preview'],
                ['text' => 'To send it, click "Send by email": choose the "Send from" mailbox, how to send it (as an attachment with a covering email, or as the body of the email itself), the attachment format (PDF or Word), the recipients and the CC, then click "Send". "A separate email to each recipient" sends each party their own email.', 'shot' => 'letters-4-email'],
            ],
            'tips' => [
                'Templates and letterheads are managed under "Settings → Templates".',
                'A sent letter changes from "Draft" to "Sent", with the date it was sent.',
            ],
        ],

        [
            'id' => 'minutes',
            'title' => 'Meeting minutes',
            'description' => 'Minutes record a meeting with the matter\'s parties (in person or online): who attended, the questions and answers, and the opening and closing paragraphs. They are numbered by themselves within the matter, and go through stages: draft → finalised (makes a PDF) → sent to the attendees to sign.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MatterMinutesRelation', 'Update:Matter (to create, record, finalise and send)'],
            'steps' => [
                ['text' => 'Open the "Meeting minutes" tab: the table shows the number, meeting, template, status, number of answers and attendees, with the "New minutes" button.', 'shot' => 'minutes-0'],
                ['text' => 'Click "New minutes": choose the "Template", the linked "Meeting" from the calendar (optional), the meeting\'s date and time and the letterhead, add the questions to ask, then click "Submit".', 'shot' => 'minutes-1-new'],
                ['text' => 'In the list of minutes, each has actions: "Live view" to follow its text, "Record the meeting" to record who attended and the answers, "Preview", "Finalize", "Send to attendees", and the PDF and Word buttons.', 'shot' => 'minutes-2-list'],
                ['text' => 'The "Preview" button shows the minutes as a PDF before they are finalised.', 'shot' => 'minutes-4-open'],
                ['text' => 'After finalising, click "Send to attendees". Each attendee has their own row with their name, "Capacity" (which you can edit), email and WhatsApp, and the "By email" and "By WhatsApp" switches, even when a party and their representative share the same email or phone. An attendee\'s email and phone are theirs alone and are not copied to the party they attended for.', 'shot' => 'minutes-5-send'],
                ['text' => 'Choose "Send from" and "How to send": "Separately, to each one", "By party, with its representatives" (the party, their representatives and whoever attended for them in one email), or "All in one email". Then "CC", "Email template", "Subject" and "More attachments".', 'shot' => 'minutes-6-mode'],
                ['text' => 'The "Preview" section shows the email as the first recipient will get it: "To" (each attendee with their name and capacity), "CC", "Subject", "Attachments" and the text with the placeholders filled in, and a preview of the WhatsApp message too. Then click "Send".', 'shot' => 'minutes-7-preview'],
            ],
            'tips' => [
                '"Finalize" freezes the text of the minutes and saves a PDF in the matter\'s files, so check the answers and attendees before it.',
                '"Send to attendees" sends the minutes by email or WhatsApp to each attendee to sign, and the signed copies come back to the matter.',
                'In the email template: {{recipient.salutation}} writes all the email\'s recipients with their names and capacities, one per line, and {{recipient.role}} is the recipient\'s capacity.',
                'In "Record the meeting", a "Save" button next to "Next" saves what was recorded without moving on, and a "Join the meeting" button is for online meetings. "Live view" follows where the text is being typed.',
            ],
        ],

        [
            'id' => 'contacts',
            'title' => 'Contacts and editing them',
            'description' => 'The "Contacts" tab gathers everyone the matter has dealt with: its parties and their representatives, and everyone who got its letters, minutes and emails or wrote to it by email. Each row has the "Name", "Capacity", "Email", "Other emails (from their replies)", "Phones" and "Source" (party to the matter, minutes, email received…). "Edit" on a party\'s row changes their name, phones and email in the party\'s own details, so it changes wherever they appear, not on this matter only, and it is checked as on the party\'s page. For someone we only emailed or who only emailed us (not a party), editing saves their name, capacity and phones (from any country) on this matter only, for their email address.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MatterContactsTab', 'Update:Party (to edit a party)', 'Update:Matter (to edit someone who came by email)'],
            'steps' => [
                ['text' => 'Open the matter and go to the "Contacts" tab. In the example, م. رامي حداد only came by email, so his source is "Email received".', 'shot' => 'contacts-1'],
                ['text' => 'Click "Edit" on a party\'s row: the window reminds you that the change is "Saved on the party: changed wherever they appear, not on this matter only.". Edit the name, phones and email, then click "Save".', 'shot' => 'contacts-2-party'],
                ['text' => 'Click "Edit" on the row of someone who came by email: the "Name", "Capacity" and "Phone" are saved on this matter only. Then click "Save".', 'shot' => 'contacts-3-mail'],
            ],
            'tips' => [
                '"Other emails" on the row adds other email addresses for the person on this matter, copied in whenever we email them on it, without changing their details in the parties table.',
                '"Export contacts" (in the tab and at the top of the page) exports them, edits included.',
                'Anyone not allowed to edit parties does not see "Edit" on a party\'s row.',
            ],
        ],

        [
            'id' => 'history',
            'title' => 'The matter\'s history',
            'description' => 'The "History" tab (the last tab) shows everything that happened to the matter and on it, newest first: its details and parties, requests, fees and collections, notes, files, minutes (created and finalised), letters, emails sent, received and filed, and events. Each row has "When", "Type", "Details" (with each field changed, from what to what) and "By". It is read from the activity log, and stays even after what it is about is deleted.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:MatterHistoryTab'],
            'steps' => [
                ['text' => 'Open the matter and go to the "History" tab. The filter icon narrows the history to one type (fee, file, email…).', 'shot' => 'history-1'],
            ],
        ],

        [
            'id' => 'delete-restore',
            'title' => 'Delete and restore a matter',
            'description' => 'Deleting hides the matter in the "Deleted" tab, and you can restore it from there. Force deleting wipes it for good with its data, and cannot be undone.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Delete:Matter', 'ViewTrashed:Matter', 'Restore:Matter', 'ForceDelete:Matter'],
            'steps' => [
                ['text' => 'Open the matter and click the red "Delete" button, or the bin icon in the list.', 'shot' => 'delete-0'],
                ['text' => 'A confirmation window with the matter\'s name appears. Click "Delete" to confirm.', 'shot' => 'delete-1'],
                ['text' => 'A "Deleted" message appears and you return to the list.', 'shot' => 'delete-2'],
                ['text' => 'To see deleted matters, open the "Deleted" tab. The icons on the row: view, edit, "Restore" and "Force delete".', 'shot' => 'deleted-tab'],
                ['text' => 'Click the "Restore" icon.', 'shot' => 'deleted-1'],
                ['text' => 'Click "Restore" in the confirmation window.', 'shot' => 'deleted-2-confirm'],
                ['text' => 'A "Restored" message appears and the matter goes back to where it was.', 'shot' => 'deleted-3-done'],
            ],
            'tips' => ['Restoring a matter also restores its deleted supplementary matters.'],
        ],

        [
            'id' => 'export',
            'title' => 'Export matters to Excel',
            'description' => 'Makes an Excel file of all the matters shown, or by assistant, with the columns you choose.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Export:Matter'],
            'steps' => [
                ['text' => 'In the matters list, click "Export All" (orange). Choose the columns you want (untick the ones you do not need), then click "Export".', 'shot' => 'export-1'],
                ['text' => '"Export by Assistant" gives a file grouped by each assistant and their matters, the same way.', 'shot' => 'export-2'],
            ],
            'tips' => ['When the export is done, a notification in the bell gives you a link to download the file.'],
        ],

        [
            'id' => 'bulk',
            'title' => 'Bulk actions on several matters',
            'description' => 'Select more than one matter with the checkboxes to apply one action to them: delete, "Final Report Submission" for a group of matters, or restore and force delete in the Deleted tab.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['BulkUpdateFinalReportDate:Matter', 'DeleteAny:Matter', 'RestoreAny:Matter', 'ForceDeleteAny:Matter'],
            'steps' => [
                ['text' => 'Tick the matters you want at the start of each row.', 'shot' => 'bulk-1'],
                ['text' => 'Open the "Actions" menu above the table, choose the action, then confirm.', 'shot' => 'bulk-2'],
            ],
            'tips' => ['"Final Report Submission" skips the matters whose report was already delivered.'],
        ],
    ],
];
