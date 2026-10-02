<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A meeting's minutes open and close with their own paragraphs — written in
 * the template ({{minutes.opening}}, {{minutes.closing}}), completed while
 * recording — and keep when the meeting ended ({{minutes.end_time}}), taken
 * when they are finalised.
 */
return new class extends Migration
{
    private const OPENING = 'فتح هذا المحضر اليوم {{meeting.day}} الموافق {{meeting.date}} الساعة {{meeting.time}} من خلال الاجتماع عن بُعد عبر تطبيق ميكروسوفت تيمز عبر الرابط المرسل للأطراف وذلك بحضور كل من:';

    private const CLOSING = 'قررت الخبرة تزويد طرفي الدعوى بنسخة من محضر اجتماع الخبرة للتوقيع عليه، مع منح الأطراف أجلاً ينتهي يوم {{input.documents_deadline.day}} الموافق {{input.documents_deadline}} لتقديم المستندات المطلوبة، وأجلاً حتى يوم {{input.memos_deadline.day}} الموافق {{input.memos_deadline}} لتقديم المذكرات الختامية بدون مستندات، وأقفل المحضر عقب إثبات ما تقدم بتاريخه.';

    public function up(): void
    {
        Schema::table('matter_minutes', function (Blueprint $table) {
            $table->text('opening')->nullable()->after('items');
            $table->text('closing')->nullable()->after('opening');
            $table->dateTime('ended_at')->nullable()->after('meeting_at');
        });

        Schema::table('letter_templates', function (Blueprint $table) {
            $table->text('minutes_opening')->nullable()->comment('minutes: the opening paragraph to start from');
            $table->text('minutes_closing')->nullable()->comment('minutes: the closing paragraph to start from');
        });

        $this->updateTemplate();
    }

    public function down(): void
    {
        Schema::table('matter_minutes', function (Blueprint $table) {
            $table->dropColumn(['opening', 'closing', 'ended_at']);
        });

        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropColumn(['minutes_opening', 'minutes_closing']);
        });
    }

    /**
     * The minutes template added with the minutes: its opening and closing
     * paragraphs become the placeholders, their wording the starting text —
     * the closing with the time the meeting ended, once it has.
     */
    private function updateTemplate(): void
    {
        $template = DB::table('letter_templates')->where('slug', 'remote-expert-meeting-minutes')->first();
        if (! $template) {
            return;
        }

        $closing = str_replace('بتاريخه.', 'بتاريخه<< في تمام الساعة {{minutes.end_time}}>>.', self::CLOSING);
        $body = str_replace(
            ['<p>'.self::OPENING.'</p>', '<p>'.self::CLOSING.'</p>'],
            ['<p>{{minutes.opening}}</p>', '<p>{{minutes.closing}}</p>'],
            (string) $template->body,
        );

        DB::table('letter_templates')->where('id', $template->id)->update([
            'body' => $body,
            // Only where the template still has them, or nothing would print.
            'minutes_opening' => str_contains($body, '{{minutes.opening}}') ? self::OPENING : null,
            'minutes_closing' => str_contains($body, '{{minutes.closing}}') ? $closing : null,
        ]);
    }
};
