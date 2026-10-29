<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter type's own folder structure and name for the emails that come in from each side — the plaintiff's, the
 * defendant's — and its own name for the general ones (App\Services\MMS\ReceivedEmailFiling). Empty: the setting's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->string('onedrive_received_plaintiff', 500)->nullable()->after('onedrive_received_emails')->comment('Folder structure for emails from the plaintiff side; empty: the setting, else the received emails one');
            $table->string('onedrive_received_defendant', 500)->nullable()->after('onedrive_received_plaintiff')->comment('Folder structure for emails from the defendant side; empty: the setting, else the received emails one');
            $table->string('received_name', 500)->nullable()->after('onedrive_received_defendant')->comment('Name pattern for received emails; empty: the setting');
            $table->string('received_name_plaintiff', 500)->nullable()->after('received_name')->comment('Name pattern for emails from the plaintiff side; empty: the setting, else the general one');
            $table->string('received_name_defendant', 500)->nullable()->after('received_name_plaintiff')->comment('Name pattern for emails from the defendant side; empty: the setting, else the general one');
        });
    }

    public function down(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn(['onedrive_received_plaintiff', 'onedrive_received_defendant', 'received_name', 'received_name_plaintiff', 'received_name_defendant']);
        });
    }
};
