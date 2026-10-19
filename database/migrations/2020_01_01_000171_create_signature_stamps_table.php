<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signatures and stamps people put on PDFs: each one's own, and the office's (shared) ones. The image
 * is kept on the private attachments disk and only ever sent through a route that checks who may.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_stamps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind', 20)->comment('signature | stamp');
            // Whoever made it; a shared one outlives them.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->string('path');
            $table->string('disk', 40);
            $table->decimal('width_mm', 6, 2)->comment('How wide it is first put on a page');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_stamps');
    }
};
