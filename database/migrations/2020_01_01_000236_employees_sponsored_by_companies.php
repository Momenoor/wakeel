<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each employee's sponsor one of the office's companies (Human Resources → Companies): the one whose
 * establishment card their visa is under. The sponsor typed on a profile until now stays as it was — for one
 * that is no company of the office's — and is linked to the company already of that name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('sponsor_name')->constrained('companies')->nullOnDelete();
        });

        $companies = DB::table('companies')->whereNull('deleted_at')->get(['id', 'name', 'name_ar']);

        foreach (DB::table('employee_profiles')->whereNotNull('sponsor_name')->where('sponsor_name', '!=', '')->distinct()->pluck('sponsor_name') as $sponsor) {
            $key = mb_strtolower(trim($sponsor));
            $company = $companies->first(fn ($company): bool => in_array($key, [mb_strtolower(trim((string) $company->name)), mb_strtolower(trim((string) $company->name_ar))], true));

            if ($company) {
                DB::table('employee_profiles')->where('sponsor_name', $sponsor)->update(['company_id' => $company->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
