<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Meeting minutes (محاضر) on a matter: numbered per matter, prepared with
 * the questions to ask, then filled in at the meeting — who attended, the
 * answers — and finalised as a PDF among the matter's attachments.
 *
 * Also: the Minutes tab's permissions, for whoever can see matters; and a
 * ready-made minutes template, as the office writes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('letter_template_id')->nullable()->constrained('letter_templates')->nullOnDelete();
            $table->foreignId('letterhead_id')->nullable()->constrained('letterheads')->nullOnDelete();
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->unsignedInteger('number');
            $table->dateTime('meeting_at')->nullable();
            $table->text('meeting_link')->nullable();
            $table->json('attendees')->nullable()->comment('[{present, title, name, capacity, id_number, phone, party_id}]');
            $table->json('items')->nullable()->comment('[{type: question|comment, text, answer}]');
            $table->json('inputs')->nullable()->comment("the template's own fields");
            $table->longText('body')->nullable()->comment('the wording, kept when finalised');
            $table->string('status')->default('draft');
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['matter_id', 'number']);
        });

        $this->grantTab();
        $this->addTemplate();
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_minutes');
    }

    /**
     * The Minutes tab and its table, for whoever can see a matter.
     */
    private function grantTab(): void
    {
        $sources = DB::table('permissions')->whereIn('name', ['View:Matter', 'ViewOwn:Matter'])->pluck('id');

        foreach ([ScreenPermissions::MATTER_MINUTES, ScreenPermissions::MATTER_MINUTES_TAB] as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $sources)->distinct()->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }

            foreach (DB::table('model_has_permissions')->whereIn('permission_id', $sources)->select(['model_type', 'model_id'])->distinct()->get() as $row) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $id, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The office's remote expert-meeting minutes, as a template — unless
     * there's a minutes template already.
     */
    private function addTemplate(): void
    {
        if (DB::table('letter_templates')->where('category', 'minutes')->exists()) {
            return;
        }

        $body = '<h2 style="text-align: center">محضر الخبرة الحسابية عن بُعد رقم ({{minutes.number}})</h2>'
            .'<p style="text-align: center"><strong>في الدعوى رقم {{matter.reference}} {{matter.type}} - {{matter.court}}</strong></p>'
            .'<p>فتح هذا المحضر اليوم {{meeting.day}} الموافق {{meeting.date}} الساعة {{meeting.time}} من خلال الاجتماع عن بُعد عبر تطبيق ميكروسوفت تيمز عبر الرابط المرسل للأطراف وذلك بحضور كل من:</p>'
            .'<p>{{minutes.attendees}}</p>'
            .'<p><strong>الخبير:</strong> عرّف الخبير عن نفسه للحاضرين بصفته الخبير المعين في الدعوى وأبرز له أمام الشاشة بطاقة الخبير الخاصة به، كما قام بالتعريف بمساعد الخبير {{matter.assistants}}</p>'
            .'<ol>'
            .'<li><p>تم التحقق من شخصية الحاضر وسند تمثيله والسابق إرساله للخبرة.</p></li>'
            .'<li><p>استلمت الخبرة من طرفي الدعوى مستندات وسيتم موافاة كل طرف بما قدم من مستندات من الطرف الآخر.</p></li>'
            .'<li><p>تمت مناقشة الحاضرين عبر التطبيق المشار إليه أعلاه مع مشاركة محتوى محضر المناقشة عبر الشاشة وذلك على النحو التالي:</p></li>'
            .'</ol>'
            .'<p>{{minutes.qa}}</p>'
            .'<p></p>'
            .'<p>قررت الخبرة تزويد طرفي الدعوى بنسخة من محضر اجتماع الخبرة للتوقيع عليه، مع منح الأطراف أجلاً ينتهي يوم {{input.documents_deadline.day}} الموافق {{input.documents_deadline}} لتقديم المستندات المطلوبة، وأجلاً حتى يوم {{input.memos_deadline.day}} الموافق {{input.memos_deadline}} لتقديم المذكرات الختامية بدون مستندات، وأقفل المحضر عقب إثبات ما تقدم بتاريخه.</p>'
            .'<p>{{signature}}</p>';

        DB::table('letter_templates')->insert([
            'name' => 'محضر الخبرة الحسابية عن بُعد',
            'slug' => 'remote-expert-meeting-minutes',
            'subject' => 'محضر الخبرة الحسابية عن بُعد رقم ({{minutes.number}})',
            'body' => $body,
            'placeholders' => '[]',
            'locale' => 'ar',
            'is_active' => true,
            'is_default' => false,
            'category' => 'minutes',
            'inputs' => json_encode([
                ['key' => 'documents_deadline', 'label' => 'آخر موعد لتقديم المستندات', 'type' => 'date', 'required' => false],
                ['key' => 'memos_deadline', 'label' => 'آخر موعد لتقديم المذكرات الختامية', 'type' => 'date', 'required' => false],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
