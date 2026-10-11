<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each user's own to-do list — the sticky note on every page: a line for a
 * day, ticked off when done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 500);
            $table->date('due_on')->comment('the day it is for');
            $table->dateTime('done_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
