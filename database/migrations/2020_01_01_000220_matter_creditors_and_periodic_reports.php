<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter's creditors — not its parties (a bankruptcy's): each with its claim and its addresses, its own or its
 * agent's, their emails filed in a folder of their own (App\Services\MMS\ReceivedEmailFiling). And its periodic
 * reports: the progress steps each covers, their files moved into its folder (App\Services\MMS\PeriodicReports).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->boolean('has_creditors')->default(false)->after('active')->comment('Its matters keep a list of creditors (a bankruptcy)');
            $table->string('onedrive_received_creditors', 500)->nullable()->after('onedrive_received_defendant')->comment('Folder structure for emails from creditors and their agents; empty: the setting, else the received emails one');
            $table->string('received_name_creditor', 500)->nullable()->after('received_name_defendant')->comment('Name pattern for emails from creditors; empty: the setting, else the general one');
            $table->string('onedrive_periodic_reports', 500)->nullable()->after('onedrive_signed_minutes')->comment('Folder of a periodic report ({{number}}); empty: the setting');
        });

        Schema::create('matter_creditors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('phones')->nullable();
            $table->decimal('claim_amount', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('matter_creditor_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_creditor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191);
            $table->boolean('agent')->default(false)->comment("The creditor's lawyer or agent, not the creditor");
            $table->string('agent_name')->nullable();
            $table->timestamps();

            // One creditor an address, on a matter.
            $table->unique(['matter_id', 'email']);
        });

        Schema::create('matter_periodic_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('folder', 500)->nullable()->comment('Its folder in the matter\'s OneDrive folder');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('moved_at')->nullable()->comment('When its steps\' files were last moved');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['matter_id', 'number']);
        });

        Schema::table('matter_progress', function (Blueprint $table) {
            $table->foreignId('periodic_report_id')->nullable()->after('source_id')->constrained('matter_periodic_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matter_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('periodic_report_id');
        });
        Schema::dropIfExists('matter_periodic_reports');
        Schema::dropIfExists('matter_creditor_addresses');
        Schema::dropIfExists('matter_creditors');
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn(['has_creditors', 'onedrive_received_creditors', 'received_name_creditor', 'onedrive_periodic_reports']);
        });
    }
};
