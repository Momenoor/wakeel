<?php

use App\Services\MMS\MatterProgressRecorder;
use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * A matter's progress: each step — a letter issued or sent, a meeting and
 * its minutes, an email to the parties, a report, documents received … —
 * with its type, name and date. The Progress tab for whoever can see a
 * matter; filled with what each matter already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 500);
            $table->dateTime('happened_at');
            $table->text('details')->nullable();
            $table->nullableMorphs('source', 'matter_progress_source_index');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['matter_id', 'happened_at']);
        });

        $this->grantTab();

        // What each matter already has. Never stops the update.
        try {
            MatterProgressRecorder::backfill();
        } catch (Throwable $e) {
            Log::warning('Matter progress backfill: '.$e->getMessage());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_progress');
    }

    private function grantTab(): void
    {
        $sources = DB::table('permissions')->whereIn('name', ['View:Matter', 'ViewOwn:Matter'])->pluck('id');
        $name = ScreenPermissions::MATTER_PROGRESS_TAB;

        $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

        foreach (DB::table('role_has_permissions')->whereIn('permission_id', $sources)->distinct()->pluck('role_id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
        }

        foreach (DB::table('model_has_permissions')->whereIn('permission_id', $sources)->select(['model_type', 'model_id'])->distinct()->get() as $row) {
            DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $id, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
