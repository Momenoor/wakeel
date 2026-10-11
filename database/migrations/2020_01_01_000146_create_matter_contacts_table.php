<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses a matter's party (or creditor, or anyone emailed on it) brings
 * in — put in To or CC of their reply — kept as theirs on this matter only,
 * not on the party: copied in when they are emailed on it again, and in the
 * matter's contacts export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete()->comment('whose: the party, when one has the address below');
            $table->string('owner_email', 191)->comment('whose: the address their email came from');
            $table->string('owner_name', 191)->nullable();
            $table->string('email', 191);
            $table->foreignId('matter_email_id')->nullable()->constrained('matter_emails')->nullOnDelete()->comment('the email it came in');
            $table->timestamps();

            $table->unique(['matter_id', 'owner_email', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_contacts');
    }
};
