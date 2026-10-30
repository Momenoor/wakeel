<?php

$everyone = 'Every user signed in to the system';
$admin = 'Super admin (super-admin) / system administrator';

return [
    'title' => 'Quick start and demo data',
    'intro' => 'This section shows you the basics of the system: signing in, changing the language, the dashboard, your profile and font size. It then shows how to fill a demo copy of the system with complete fake data, so you can try every section without touching real data. All the pictures in this guide are taken from that demo copy.',
    'actions' => [

        [
            'id' => 'login',
            'title' => 'Sign in',
            'description' => 'You sign in with the username or email address and the password your system administrator gave you.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Open the system\'s link in your browser. The "Sign in" page appears.', 'shot' => 'login-1'],
                ['text' => 'Type your "Username or Email" and "Password" (the eye icon shows what you typed). Tick "Remember me" if the device is your own, then click "Sign in".', 'shot' => 'login-2'],
            ],
            'tips' => ['If you forget your password, ask your system administrator to reset it. To move between the office\'s systems (matters and properties), use the systems menu at the top of the side menu (it opens under its button inside the menu; when the menu is collapsed, only its icon shows).', '"Sign out" in the user menu signs you out on this device only. "Remember me" stays on your other devices.'],
        ],

        [
            'id' => 'dashboard',
            'title' => 'The dashboard',
            'description' => 'The first page after you sign in. It shows a summary of your work: number cards (matters without an assistant, open requests, waiting for the final report, this week\'s sessions, paid and unpaid fees), upcoming sessions, the calendar and charts. Each user sees only the widgets their role allows.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'The dashboard appears right after you sign in, or open it from "Dashboard" in the side menu.', 'shot' => 'dashboard-1'],
                ['text' => 'The side menu holds every section of the system, in groups: Matters, Communication, Finance, Human Resources, Administration. Click the arrow to collapse or expand it. For long menus, follow the groups.', 'shot' => 'dashboard-nav'],
            ],
        ],

        [
            'id' => 'search',
            'title' => 'Global search',
            'description' => 'The "Search" box in the top bar (or Ctrl+K) searches the whole system, within what you are allowed to see: matters by their number however it is written (7777, 7777/2026, 2026/7777, in Arabic digits or with a backslash); parties by name, phone number or email, showing the party with their matters and requests; employees by anything they are known by (name, employee number, phone, email, Emirates ID, passport) with their requests and leave; and requests by their number (such as #12).',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Press Ctrl+K or click the "Search" box and type what you are looking for, for example a phone number: the party appears with their number and email. Click the result to open it.', 'shot' => 'search-1'],
            ],
            'tips' => ['If someone calls you and you do not know their matter, search by their phone number or email.'],
        ],

        [
            'id' => 'language',
            'title' => 'Change the interface language',
            'description' => 'The system works in Arabic and English, and the page direction (right-to-left or left-to-right) changes with the language on its own.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Click the language flag in the top bar.', 'shot' => 'lang-1'],
                ['text' => 'Choose "English" or "Arabic". The interface changes straight away and your choice is kept for your next visits.', 'shot' => 'lang-2'],
            ],
        ],

        [
            'id' => 'profile',
            'title' => 'Your profile and font size',
            'description' => 'From the user menu you edit your name, avatar, email and how you receive notifications, change your password, and set the font size to suit your screen.',
            'roles' => [$everyone],
            'permissions' => [],
            'steps' => [
                ['text' => 'Click your avatar in the top bar to open the menu: "Profile", the "Font Size" slider, the appearance switch and "Sign out".', 'shot' => 'usermenu-1'],
                ['text' => 'In "Profile", upload your picture, edit your name, email and display name, choose your notifications under "My notifications", change your password, then click "Save changes".', 'shot' => 'profile-1'],
                ['text' => 'The page has two columns: "Account" and the password, and below them the "My notifications" groups (what you are told of and how: the bell, email or WhatsApp; what your role requires stays on). In "Account", the "Theme" field picks the interface colours for you, unless the management makes everyone use the office theme.'],
            ],
            'tips' => ['The default font size is 16. On large screens, 13 or 14 is comfortable and shows more on the page.'],
        ],

        [
            'id' => 'demo-data',
            'title' => 'Fill the system with complete demo data',
            'description' => 'To try the system or train staff without using real data, the system has a demo data seeder that creates realistic Arabic data: users with avatars, a fake logo, courts and matter types, parties and experts, thirty matters at every stage with their fees, payments, requests, notes and attachments, letterheads, letter templates, sent letters and meeting minutes, and calendar appointments. You run it once on a separate, empty database.',
            'roles' => [$admin.' (whoever can run commands on the server)'],
            'permissions' => [],
            'steps' => [
                ['text' => 'Create a new, empty database for the demo copy (for example in MySQL). Never use the office\'s real database.', 'code' => 'CREATE DATABASE wakeel_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'],
                ['text' => 'In the demo copy\'s .env file, point the connection to the new database.', 'code' => "DB_CONNECTION=mysql\nDB_DATABASE=wakeel_demo"],
                ['text' => 'Create the tables.', 'code' => 'php artisan migrate --force'],
                ['text' => 'Run the demo seeder. It takes about half a minute.', 'code' => 'php artisan db:seed --class="Database\\Seeders\\Demo\\DemoDataSeeder"'],
                ['text' => 'Sign in with one of the demo accounts. They all share one password, set in the PASSWORD constant in database/seeders/Demo/DemoCoreSeeder.php.', 'code' => "demo@wakeel.test      Super admin\nfatima@wakeel.test    Human resources\nkhaled@wakeel.test    Accountant\nahmed@wakeel.test     Assistant\nmaryam@wakeel.test    Assistant\nyousef@wakeel.test    Assistant"],
            ],
            'tips' => [
                'To start the demo again from scratch, run php artisan migrate:fresh --force, then run the seeder again. This wipes all the data in that database, so make sure it is the demo one.',
                'The demo users\' default language is Arabic, with a font size of 13.',
            ],
        ],
    ],
];
