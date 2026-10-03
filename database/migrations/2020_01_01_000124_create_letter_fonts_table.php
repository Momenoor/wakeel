<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fonts for letters and minutes (Calibri …): their files uploaded here —
 * regular, bold, italic, bold italic — kept on the server, not in the code;
 * and the font each template is written in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_fonts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('as Word knows it, e.g. Calibri');
            $table->json('files')->nullable()->comment('{regular, bold, italic, bold_italic} => path on the local disk');
            $table->timestamps();
        });

        Schema::table('letter_templates', function (Blueprint $table) {
            $table->foreignId('letter_font_id')->nullable()->constrained('letter_fonts')->nullOnDelete()
                ->comment('the font it is written in; empty: the standard one');
        });

        // For whoever manages the letter templates.
        $sources = DB::table('permissions')->where('name', 'ViewAny:LetterTemplate')->pluck('id');
        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $ability) {
            $name = $ability.':LetterFont';
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $sources)->distinct()->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('letter_font_id');
        });

        Schema::dropIfExists('letter_fonts');
    }
};
