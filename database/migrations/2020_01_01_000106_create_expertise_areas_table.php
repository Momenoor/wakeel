<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Experts' areas of expertise, managed in Settings instead of fixed in the
 * party form. A party stores the area's `key` (in its role JSON), so the
 * eight areas the form used to list keep their keys and every expert keeps
 * their area.
 */
return new class extends Migration
{
    private const AREAS = [
        'accounting' => ['Accounting', 'المحاسبة'],
        'finance' => ['Finance', 'المالية'],
        'technology' => ['Technology', 'التكنولوجيا'],
        'engineering' => ['Engineering', 'الهندسة'],
        'architecture' => ['Architecture', 'العمارة'],
        'civil' => ['Civil', 'الهندسة المدنية'],
        'it' => ['IT', 'تقنية المعلومات'],
        'banking' => ['Banking', 'المصرفية'],
    ];

    private const ABILITIES = ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore', 'ForceDelete', 'ForceDeleteAny', 'RestoreAny', 'Replicate', 'Reorder'];

    public function up(): void
    {
        Schema::create('expertise_areas', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('What a party stores; never changes');
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        $sort = 0;

        foreach (self::AREAS as $key => [$en, $ar]) {
            DB::table('expertise_areas')->insert([
                'key' => $key,
                'name_en' => $en,
                'name_ar' => $ar,
                'is_active' => true,
                'sort' => ++$sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Whoever could create parties chose these areas; they manage the
        // list now (the role editor can change that).
        $from = DB::table('permissions')->where('name', 'Create:Party')->pluck('id');
        $roles = DB::table('role_has_permissions')->whereIn('permission_id', $from)->distinct()->pluck('role_id');

        foreach (self::ABILITIES as $ability) {
            $name = "{$ability}:ExpertiseArea";
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

            foreach ($roles as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('expertise_areas');
    }
};
