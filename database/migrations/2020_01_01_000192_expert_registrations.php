<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A certified expert's registration number at each court they are registered with (Parties → the expert →
 * Court registrations) — a matter's letter gives the number at its court ({{matter.experts.registration}}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->string('number', 100);
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'court_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_registrations');
    }
};
