<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A to-do shared by mentioning (@name in it): on each mentioned user's list
 * too, ticked off once for all — and who ticked it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('todo_id')->constrained('todos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['todo_id', 'user_id']);
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->foreignId('done_by')->nullable()->after('done_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('done_by');
        });
        Schema::dropIfExists('todo_user');
    }
};
