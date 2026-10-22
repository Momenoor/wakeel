<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * An email that may be about an assistant's matter waits in Unfiled emails — the assistant told of it in
 * their bell's Emails tab and able to file it: the page (which shows them only those of their own matters)
 * given to whoever sees their own matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        ScreenPermissions::grant('View:UnfiledEmails', ['ViewOwn:Matter']);
    }

    public function down(): void
    {
        // Kept: who may see the page is the roles' to change.
    }
};
