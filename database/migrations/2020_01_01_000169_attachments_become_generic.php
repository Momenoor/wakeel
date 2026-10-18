<?php

use App\Enums\AttachmentCategory;
use App\Models\Matter;
use App\Models\MatterRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments of anything — a fee, an allocation, a request, a matter … — not only of a matter: each
 * file has an owner (any model), keeps the matter it belongs to as its context (so a matter's documents
 * can list its fees' and allocations' files too), a category, and where it is kept. Files made before
 * stay as they are: owned by their request or their matter, on the public disk, until they are moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('matter_id')->nullable()->change();

            $table->string('attachable_type')->nullable()->after('matter_request_id')->comment('the owner: any model');
            $table->unsignedBigInteger('attachable_id')->nullable()->after('attachable_type');
            $table->string('category', 40)->nullable()->after('type')->comment('AttachmentCategory');
            $table->string('disk', 30)->default('public')->after('path')->comment('where the file is kept');
            $table->string('original_name')->nullable()->after('name');
            $table->string('mime', 120)->nullable()->after('extension');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime');
            $table->string('checksum', 64)->nullable()->after('size_bytes')->comment('sha256');
            $table->text('description')->nullable();
            $table->string('source', 20)->nullable()->comment('user | whatsapp | email | system');
            $table->text('onedrive_url')->nullable();
            $table->string('onedrive_status', 20)->nullable()->comment('queued | saved | failed');
            $table->text('onedrive_error')->nullable();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index(['matter_id', 'category']);
            $table->index('checksum');
        });

        // Owned by its request, else by its matter; the category from its old type; its size as a number.
        DB::table('attachments')->whereNotNull('matter_request_id')->update(['attachable_type' => MatterRequest::class, 'attachable_id' => DB::raw('matter_request_id')]);
        DB::table('attachments')->whereNull('matter_request_id')->whereNotNull('matter_id')->update(['attachable_type' => Matter::class, 'attachable_id' => DB::raw('matter_id')]);

        foreach (['matter-request', 'correspondence', 'minutes_signed'] as $type) {
            DB::table('attachments')->where('type', $type)->update(['category' => AttachmentCategory::fromLegacyType($type)->value]);
        }
        DB::table('attachments')->whereNull('category')->update(['category' => AttachmentCategory::GENERAL->value]);

        DB::table('attachments')->select(['id', 'size'])->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                if (ctype_digit((string) $row->size)) {
                    DB::table('attachments')->where('id', $row->id)->update(['size_bytes' => (int) $row->size]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['attachable_type', 'attachable_id']);
            $table->dropIndex(['matter_id', 'category']);
            $table->dropIndex(['checksum']);
            $table->dropSoftDeletes();
            $table->dropColumn(['attachable_type', 'attachable_id', 'category', 'disk', 'original_name', 'mime', 'size_bytes', 'checksum', 'description', 'source', 'onedrive_url', 'onedrive_status', 'onedrive_error']);
        });
    }
};
