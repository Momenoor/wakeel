<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The letter builder: letterheads (page design), the reusable item library
 * for long numbered lists, and what issued letters need to remember —
 * their reference number, the inputs they were filled with, and a frozen
 * copy of the letter as issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letterheads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->string('orientation')->default('portrait');
            // Page margins in millimetres — where the letter text flows.
            $table->decimal('margin_top', 6, 2)->default(45);
            $table->decimal('margin_right', 6, 2)->default(20);
            $table->decimal('margin_bottom', 6, 2)->default(30);
            $table->decimal('margin_left', 6, 2)->default(20);
            // Full-page background images: the printed letterhead.
            $table->string('first_page_background')->nullable();
            $table->string('other_pages_background')->nullable();
            $table->string('watermark_type')->default('none'); // none | text | image
            $table->string('watermark_text')->nullable();
            $table->string('watermark_image')->nullable();
            $table->decimal('watermark_opacity', 4, 2)->default(0.08);
            $table->string('signature_image')->nullable();
            $table->string('stamp_image')->nullable();
            // Freely placed elements (reference, date, logo, text boxes, …).
            $table->json('elements')->nullable();
            $table->timestamps();
        });

        Schema::create('letter_items', function (Blueprint $table) {
            $table->id();
            $table->string('group');
            $table->text('text');
            $table->foreignId('type_id')->nullable()->constrained('types')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['group', 'sort']);
        });

        Schema::table('letter_templates', function (Blueprint $table) {
            $table->foreignId('letterhead_id')->nullable()->after('category')->constrained('letterheads')->nullOnDelete();
            // The form shown when issuing a letter: [{key, label, type, …}].
            $table->json('inputs')->nullable()->after('letterhead_id');
            $table->json('placeholders')->nullable()->change();
        });

        Schema::table('matter_letters', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('matter_id');
            $table->unsignedInteger('sequence')->nullable()->after('reference');
            $table->foreignId('letterhead_id')->nullable()->after('sequence')->constrained('letterheads')->nullOnDelete();
            $table->json('inputs')->nullable()->after('body');
            $table->longText('rendered_html')->nullable()->after('inputs');
            $table->date('letter_date')->nullable()->after('rendered_html');

            $table->index(['matter_id', 'sequence']);
        });

        Schema::table('matter_letter_recipients', function (Blueprint $table) {
            $table->string('role')->nullable()->after('name');
            $table->json('emails')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('matter_letter_recipients', function (Blueprint $table) {
            $table->dropColumn(['role', 'emails']);
        });

        Schema::table('matter_letters', function (Blueprint $table) {
            $table->dropIndex(['matter_id', 'sequence']);
            $table->dropConstrainedForeignId('letterhead_id');
            $table->dropColumn(['reference', 'sequence', 'inputs', 'rendered_html', 'letter_date']);
        });

        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('letterhead_id');
            $table->dropColumn('inputs');
        });

        Schema::dropIfExists('letter_items');
        Schema::dropIfExists('letterheads');
    }
};
