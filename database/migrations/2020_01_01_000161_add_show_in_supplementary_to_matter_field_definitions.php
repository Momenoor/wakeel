<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A custom field can be asked for when a supplementary matter is added from its
 * parent: the others keep the parent's value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_field_definitions', function (Blueprint $table) {
            $table->boolean('show_in_supplementary')->default(false)->comment('Shown in the supplementary matter form, to be filled for the new matter');
        });
    }

    public function down(): void
    {
        Schema::table('matter_field_definitions', function (Blueprint $table) {
            $table->dropColumn('show_in_supplementary');
        });
    }
};
