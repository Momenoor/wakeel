<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter's contact who is no party — emailed on it, on its bulk mail, written to in a letter — as corrected on
 * its Contacts tab: their name, capacity and phones, kept on that matter by their address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_contact_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->string('email', 191)->comment('whose: their address, lower case');
            $table->string('name', 191)->nullable();
            $table->string('capacity', 191)->nullable();
            $table->json('phones')->nullable();
            $table->timestamps();

            $table->unique(['matter_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_contact_details');
    }
};
