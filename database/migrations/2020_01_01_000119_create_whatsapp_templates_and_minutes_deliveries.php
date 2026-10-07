<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Each letter template's own covering email; WhatsApp message templates
 * (as approved in Meta's WhatsApp Manager); and the minutes sent to the
 * attendees to sign — by email or WhatsApp — with the signed copies they
 * send back.
 */
return new class extends Migration
{
    public const MINUTES_BODY = "إلى {{name}}،\nتحية طيبة وبعد،\nنرفق لكم محضر اجتماع الخبرة رقم ({{minutes_number}}) في الدعوى رقم {{matter_number}}، المنعقد بتاريخ {{meeting_date}}.\nنرجو التكرم بمراجعة المحضر وتوقيعه، ثم إعادة إرساله إلينا موقّعاً بالرد على هذه الرسالة نفسها (ملف PDF، أو صورة واضحة لكل صفحة).\nمع خالص الشكر والتقدير.";

    public function up(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete()
                ->comment('the covering email it is sent with, unless changed');
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('purpose', 40)->default('minutes_signature');
            $table->string('meta_name')->comment('the template name in Meta WhatsApp Manager');
            $table->string('language', 20)->default('ar');
            $table->string('header', 20)->default('document')->comment('none | document: the file goes in the header');
            $table->text('body')->comment('the approved text, for the preview');
            $table->json('parameters')->nullable()->comment('[{name, value}] — value may hold {{placeholders}}');
            $table->text('acknowledgement')->nullable()->comment('the reply when a signed copy comes back');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('minutes_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_minutes_id')->constrained('matter_minutes')->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('name');
            $table->string('channel', 20)->comment('email | whatsapp');
            $table->string('address')->comment('the email, or the WhatsApp number');
            $table->string('message_id')->nullable()->index()->comment('WhatsApp: the message id, matched by replies');
            $table->string('status', 20)->default('sent')->comment('sent | failed | signed');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->json('signed_attachments')->nullable()->comment('attachment ids of the signed copies received');
            $table->json('received_message_ids')->nullable()->comment('WhatsApp messages already taken in');
            $table->string('onedrive_url')->nullable();
            $table->text('onedrive_error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->addTemplate();
        $this->grant(['ViewAny:WhatsAppTemplate', 'View:WhatsAppTemplate', 'Create:WhatsAppTemplate', 'Update:WhatsAppTemplate', 'Delete:WhatsAppTemplate'], 'ViewAny:EmailTemplate');
        $this->grant(['View:WhatsAppSettings'], 'View:OneDriveSettings');
    }

    public function down(): void
    {
        Schema::dropIfExists('minutes_deliveries');
        Schema::dropIfExists('whatsapp_templates');

        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
        });
    }

    private function addTemplate(): void
    {
        DB::table('whatsapp_templates')->insert([
            'name' => 'محضر للتوقيع',
            'purpose' => 'minutes_signature',
            'meta_name' => 'minutes_for_signature',
            'language' => 'ar',
            'header' => 'document',
            'body' => self::MINUTES_BODY,
            'parameters' => json_encode([
                // "الأستاذة/ … المحترمة": the title and the honorific that agrees.
                ['name' => 'name', 'value' => '{{recipient.salutation}}'],
                ['name' => 'minutes_number', 'value' => '{{minutes.number}}'],
                ['name' => 'matter_number', 'value' => '{{matter.reference}}'],
                ['name' => 'meeting_date', 'value' => '{{meeting.date}}'],
            ], JSON_UNESCAPED_UNICODE),
            'acknowledgement' => 'شكراً لكم، تم استلام المحضر الموقّع وإرفاقه بملف الدعوى.',
            'is_active' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * New permissions, for whoever holds a related one.
     *
     * @param  list<string>  $names
     */
    private function grant(array $names, string $source): void
    {
        $sources = DB::table('permissions')->where('name', $source)->pluck('id');

        foreach ($names as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $sources)->distinct()->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
