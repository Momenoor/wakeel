<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * A permission that keeps someone to the matters of the assistants they administer (Users → the assistant's
 * page → "Administrators of their matters") and no others — "View administered assistants' matters only",
 * for a role that has "View own matters" (their own and their assistants') or "View all matters" otherwise.
 * Given to no one to begin with: chosen in Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        ScreenPermissions::grant('ViewAdministered:Matter', []);
    }

    public function down(): void
    {
        //
    }
};
