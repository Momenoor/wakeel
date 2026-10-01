<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved signature blocks, laid out once and dropped into any letter
 * template: the signature, the stamp, other images and text lines placed
 * freely in a box, over and under each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('width', 6, 1)->default(80)->comment('mm');
            $table->decimal('height', 6, 1)->default(45)->comment('mm');
            $table->string('align', 10)->default('left')->comment('the box on the line: left, center or right');
            $table->json('elements')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_layouts');
    }
};
