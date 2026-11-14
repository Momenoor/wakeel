<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The errors users ran into (App\Support\ErrorTracker): each with a code the user is shown, what failed and
 * where, who was signed in and what they were doing — for Settings → Error tracking. The admin role is given
 * the screen; the super admin has it as everything else.
 */
return new class extends Migration
{
    private const PERMISSION = 'View:ErrorReports';

    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();
            // What the user is shown and gives to the administrator.
            $table->string('code', 12)->unique();
            // The same error again: the same exception at the same line.
            $table->char('fingerprint', 40)->index();
            // web, livewire, queue, scheduler or console.
            $table->string('source', 16);
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('exception');
            $table->text('message')->nullable();
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('method', 10)->nullable();
            $table->text('url')->nullable();
            $table->string('route')->nullable();
            // The Livewire component and what was called on it, or the command or job.
            $table->text('action')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('input')->nullable();
            $table->longText('trace')->nullable();
            // The errors behind it, when it wraps others.
            $table->text('previous')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();
        });

        Permissions::ensure([self::PERMISSION]);

        if (($admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()) !== null) {
            $admin->givePermissionTo(Permission::findByName(self::PERMISSION, 'web'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
        Permission::query()->where('name', self::PERMISSION)->delete();
    }
};
