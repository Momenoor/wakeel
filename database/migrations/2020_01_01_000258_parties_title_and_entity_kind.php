<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An external entity's kind (a court or judicial department, a bank, a government body, a company), and a contact's
 * title at the entity it belongs to (parent_id): "أمين سر الدائرة العمالية", "Head of Litigation".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('title')->nullable()->after('legal_name');
            $table->string('entity_kind', 32)->nullable()->index()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex(['entity_kind']);
            $table->dropColumn(['title', 'entity_kind']);
        });
    }
};
