<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'System updates and OneDrive folders',
    'intro' => 'Two admin pages: "System Updates", to see the installed version, update it in one click, and back up and restore the database; and "OneDrive Folders", to create a folder automatically for each new matter in its assistant\'s OneDrive.',
    'actions' => [
        [
            'id' => 'updates',
            'title' => 'Update the system',
            'description' => 'The page shows the installed "Version" and the latest one available (with a "New" badge in the menu when there is an update). The "Check for updates" button asks the licence server, then the update runs in a series of steps, each showing its result.',
            'roles' => [$admin, 'Anyone with the permission to manage updates'],
            'permissions' => ['View:SystemUpdates'],
            'steps' => [['text' => 'Open "Settings → Maintenance → System Updates" and click "Check for updates". If there is a newer version, follow the update button that appears. The update runs in the background: a cron link starts its next steps every minute (even during maintenance mode), and the page tells you that you can close it.', 'shot' => 'page-1']],
            'tips' => ['Back up the database ("Back up now", below) and the files before updating, and do not update at the busiest time of the working day.'],
        ],
        [
            'id' => 'backups',
            'title' => 'Database backups and restore',
            'description' => 'The "Database backups" section of the "System Updates" page (super admin only). "Back up now" makes a copy of the database in steps, with a count of the rows. The last five backups made here are kept, each with "Download" and "Delete". A backup holds the database only: attached files are kept on the server or in Azure and are not in it. "Restore from a backup" replaces the whole database with a backup on the server or a file from your computer (.sql or .sql.gz, up to 50 MB). It runs on its own page, which carries the restore through to the end with the site offline, then runs any later migrations.',
            'roles' => ['Super admin (super-admin) only'],
            'permissions' => ['View:SystemUpdates'],
            'steps' => [
                ['text' => 'On "System Updates", scroll down to "Database backups". Click "Back up now" and wait for it to finish, then click "Download" to keep a copy off the server.', 'shot' => 'backups-1'],
                ['text' => 'To restore, click "Restore from a backup" and choose "Restore from": "A backup on the server" or "A file from my computer (.sql or .sql.gz)". Enter "Your password" and fill in "Type RESTORE to confirm", then click "Delete the database and restore".'],
            ],
            'tips' => ['A restore deletes every table and replaces it with the backup\'s, so everything added after the backup was made is lost. Download a backup of the database as it is now first. Afterwards, everyone signs in with the backup\'s users and their passwords.', 'For a file larger than 50 MB, copy it to the storage/app/private/backups folder (cPanel → File Manager), then choose it from the server.'],
        ],
        [
            'id' => 'onedrive',
            'title' => 'OneDrive folders for matters',
            'description' => 'When "Create OneDrive folders for new matters" is on, the system creates a folder named after each new matter in its assistant\'s Microsoft 365 OneDrive, with the "Standard subfolders", and sets the folder for signed minutes. Where each assistant\'s folders go is set from their details in Parties.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:OneDriveSettings'],
            'steps' => [
                ['text' => 'Open "Settings → Integrations → OneDrive Folders", turn on creating folders, and enter the standard subfolders and the path of the "Signed minutes subfolder".', 'shot' => 'onedrive/page-1'],
                ['text' => 'Before you rely on it, test the connection: "Create test folder" and "Remove test folder", or "Test with an assistant\'s Microsoft 365 email", then click "Save Settings".'],
            ],
            'tips' => ['This needs an Azure app (with Microsoft Graph permissions) set up in the environment file.'],
        ],
    ],
];
