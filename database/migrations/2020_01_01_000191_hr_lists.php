<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The office's own list of designations, each in Arabic and English (Human Resources → Designations), chosen
 * from on an employee's profile — a letter names it in its own language; the designations typed on profiles
 * until now are its first entries. And each employee's nationality (App\Support\Nationalities: its country's
 * code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_list_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('designation')->comment('designation');
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestamps();

            $table->unique(['kind', 'name_en']);
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->foreignId('designation_id')->nullable()->after('designation')->constrained('hr_list_items')->nullOnDelete();
            $table->string('nationality', 2)->nullable()->after('designation_id')->comment('Its country\'s code (App\Support\Nationalities)');
        });

        $now = now();

        // The designations already typed: each an entry (its Arabic and English the same, until changed).
        foreach (DB::table('employee_profiles')->whereNotNull('designation')->where('designation', '!=', '')->distinct()->pluck('designation') as $designation) {
            DB::table('hr_list_items')->insertOrIgnore(['kind' => 'designation', 'name_en' => trim($designation), 'name_ar' => trim($designation), 'created_at' => $now, 'updated_at' => $now]);
            $id = DB::table('hr_list_items')->where('kind', 'designation')->where('name_en', trim($designation))->value('id');
            DB::table('employee_profiles')->where('designation', $designation)->update(['designation_id' => $id]);
        }

        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $ability) {
            ScreenPermissions::grant($ability.':HrListItem', ['Update:EmployeeProfile']);
        }
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('nationality');
            $table->dropConstrainedForeignId('designation_id');
        });
        Schema::dropIfExists('hr_list_items');
    }
};
