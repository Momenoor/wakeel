<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each request cost — see App\Http\Middleware\TrackPerformance and the
 * Performance page. Kept for 14 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8);
            $table->string('path', 255);
            // The screen, or for Livewire's own requests the component and what was done: "chat-widget@selectConversation".
            $table->string('name', 191)->index();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('queries');
            // Queries run again, the same: one per row of a list, typically.
            $table->unsignedInteger('repeated');
            $table->text('top_query')->nullable();
            $table->decimal('memory_mb', 7, 1);
            $table->unsignedInteger('response_kb');
            // A run of the Performance page's "Check every screen".
            $table->string('check_id', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_samples');
    }
};
