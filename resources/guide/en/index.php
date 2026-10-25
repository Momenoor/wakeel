<?php

/**
 * Modules of the User Guide, grouped and in the order shown. A module appears
 * only once its file (resources/guide/en/{id}.php) exists.
 * "panel": mms | pms | shared (shared shows in both panels).
 */
$m = fn (string $group, string $id, string $panel, string $icon, string $title, string $summary): array => compact('group', 'id', 'panel', 'icon', 'title', 'summary');

return [
    'modules' => [
        $m('Getting started', 'start', 'shared', 'heroicon-o-rocket-launch', 'Quick start and demo data', 'Signing in, the language, and filling the system with demo data'),

        $m('Matter management', 'dashboard', 'mms', 'heroicon-o-home', 'Dashboard', 'Cards, sessions, the calendar and charts'),
        $m('Matter management', 'matters', 'mms', 'heroicon-o-scale', 'Matters', 'Creating matters, reports, fees, letters and minutes'),
        $m('Matter management', 'calendar', 'mms', 'heroicon-o-calendar-days', 'Calendar Events', 'Sessions, site visits and Outlook sync'),
        $m('Matter management', 'parties', 'mms', 'heroicon-o-user-group', 'Parties', 'Parties, experts, representatives and employees'),
        $m('Matter management', 'courts', 'mms', 'heroicon-o-building-library', 'Courts', 'The list of courts and their details'),
        $m('Matter management', 'types', 'mms', 'heroicon-o-tag', 'Matter Types', 'Incentive, party titles and custom fields'),
        $m('Matter management', 'expertise', 'mms', 'heroicon-o-academic-cap', 'Expertise Areas', 'The list of experts\' specialties'),
        $m('Matter management', 'reports', 'mms', 'heroicon-o-chart-bar', 'Reports', '14 reports: matters, fees, assistants and VAT'),

        $m('Correspondence and templates', 'chat', 'mms', 'heroicon-o-chat-bubble-left-right', 'Chat', 'Instant one-to-one and group conversations'),
        $m('Correspondence and templates', 'emails', 'mms', 'heroicon-o-inbox', 'Matter emails', 'The Emails tab, unfiled emails and the filing mailbox'),
        $m('Correspondence and templates', 'bulkmail', 'mms', 'heroicon-o-envelope', 'Bulk Email', 'Mail campaigns and sending personalised batches'),
        $m('Correspondence and templates', 'emailtpl', 'mms', 'heroicon-o-envelope-open', 'Email templates', 'Ready-made email wording'),
        $m('Correspondence and templates', 'lettertpl', 'mms', 'heroicon-o-document-text', 'Letter templates and the clause library', 'Letter wording, fields and clauses'),
        $m('Correspondence and templates', 'letterheads', 'mms', 'heroicon-o-document', 'Letterheads, signature blocks and fonts', 'The office\'s official paper'),
        $m('Correspondence and templates', 'whatsapp', 'mms', 'heroicon-o-chat-bubble-oval-left', 'WhatsApp templates', 'Meta templates and incoming replies'),
        $m('Correspondence and templates', 'mailsenders', 'mms', 'heroicon-o-at-symbol', 'Mail senders', 'Microsoft 365 and SMTP sending mailboxes and the filing mailbox'),
        $m('Correspondence and templates', 'pdftools', 'mms', 'heroicon-o-pencil-square', 'PDF tools', 'Signing, stamping, editing text and splitting'),

        $m('Finance', 'incentive', 'mms', 'heroicon-o-calculator', 'Incentive Calculations', 'Working out assistants\' incentives, their tiers and rules'),
        $m('Finance', 'payroll', 'mms', 'heroicon-o-banknotes', 'Payroll Runs', 'The monthly payroll, approvals and the journal voucher'),
        $m('Finance', 'loans', 'mms', 'heroicon-o-credit-card', 'Loans & Petty Cash', 'Loans, petty cash and instalment schedules'),
        $m('Finance', 'finconfig', 'mms', 'heroicon-o-cog-8-tooth', 'Financial Settings', 'Incentive rates, payroll figures and the law'),

        $m('Human Resources', 'employees', 'mms', 'heroicon-o-identification', 'Employees', 'Profiles, documents, salary structure and designations'),
        $m('Human Resources', 'employeerequests', 'mms', 'heroicon-o-hand-raised', 'Employee requests', 'Loans, tickets paid in advance and certificates'),
        $m('Human Resources', 'leave', 'mms', 'heroicon-o-calendar', 'Leave', 'Requests, approval and the absence ledger'),
        $m('Human Resources', 'tickets', 'mms', 'heroicon-o-paper-airplane', 'Flight tickets and end of service', 'Tickets and the gratuity settlement voucher'),

        $m('Property management', 'pmsdashboard', 'pms', 'heroicon-o-home', 'Property dashboard', 'A summary of collections, instalments and revenue'),
        $m('Property management', 'pmsprops', 'pms', 'heroicon-o-building-office', 'Properties and units', 'Properties, title deeds and their units'),
        $m('Property management', 'pmsowners', 'pms', 'heroicon-o-user-group', 'Owners and owner groups', 'Owners and their bank accounts'),
        $m('Property management', 'pmstenants', 'pms', 'heroicon-o-users', 'Tenants', 'The register of tenants'),
        $m('Property management', 'quotations', 'pms', 'heroicon-o-document-currency-dollar', 'Quotations', 'Rent quotations and turning them into leases'),
        $m('Property management', 'leases', 'pms', 'heroicon-o-document-check', 'Leases', 'Creating, attesting, payments and renewal'),
        $m('Property management', 'pmsconditions', 'pms', 'heroicon-o-clipboard-document-check', 'Conditions and print templates', 'Special conditions and print layouts'),
        $m('Property management', 'pmssettings', 'pms', 'heroicon-o-adjustments-horizontal', 'Property management settings', 'VAT, attestation and the late fee'),
        $m('Property management', 'pmsreports', 'pms', 'heroicon-o-chart-pie', 'Property reports', '9 reports on rent, collections and owners'),

        $m('Administration', 'users', 'shared', 'heroicon-o-users', 'Users', 'Accounts, passwords and impersonation'),
        $m('Administration', 'roles', 'shared', 'heroicon-o-shield-check', 'Roles and permissions', 'Who sees what and does what'),
        $m('Administration', 'activity', 'shared', 'heroicon-o-clipboard-document-list', 'Activity logs and the audit dashboard', 'An audit trail for every action'),
        $m('Administration', 'sysettings', 'shared', 'heroicon-o-cog-6-tooth', 'System Settings', 'Identity, dates, themes, email, integrations and Azure'),
        $m('Administration', 'maintenance', 'mms', 'heroicon-o-wrench-screwdriver', 'Maintenance pages', 'Permissions, fees and their VAT, and matter difficulty'),
        $m('Administration', 'updates', 'mms', 'heroicon-o-arrow-path', 'Updates and OneDrive folders', 'System updates, backups and OneDrive'),
    ],
];
