<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A text sent back on WhatsApp instead of the signed minutes: kept on the
 * delivery, and answered once (the template's new reply) with how to send
 * the signed copy and how to reach the office — whose phone, WhatsApp and
 * email are now settings, used everywhere as {{company.*}}.
 */
return new class extends Migration
{
    private const AR_REPLY = "شكراً لتواصلكم بخصوص محضر اجتماع الخبرة رقم ({{minutes.number}}) في الدعوى رقم {{matter.reference}}.\n"
        ."لإتمام التوقيع، يرجى إرسال المحضر موقّعاً كملف PDF أو صورة واضحة رداً على هذه الرسالة.\n"
        ."لأي استفسار أو ملاحظة، يرجى التواصل معنا على الرقم {{company.phone}} أو واتساب {{company.whatsapp}} أو عبر البريد الإلكتروني {{company.email}}.\n"
        .'{{company.name}}';

    private const EN_REPLY = "Thank you for your message regarding minutes No. {{minutes.number}} of case {{matter.reference}}.\n"
        ."To complete the signing, please send the signed minutes as a PDF or a clear photo in reply to this message.\n"
        ."For any enquiry or comment, kindly call us on {{company.phone}}, WhatsApp {{company.whatsapp}} or email {{company.email}}.\n"
        .'{{company.name}}';

    private const AR_THANKS = 'شكراً لكم، تم استلام المحضر الموقّع وإرفاقه بملف الدعوى.';

    private const AR_THANKS_CONTACT = "\nلأي استفسار يرجى التواصل على {{company.phone}} أو واتساب {{company.whatsapp}} أو {{company.email}}.";

    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->text('text_reply')->nullable()->after('acknowledgement');
        });

        Schema::table('minutes_deliveries', function (Blueprint $table) {
            $table->json('replies')->nullable()->after('received_message_ids');
            $table->timestamp('text_reply_sent_at')->nullable()->after('replies');
        });

        foreach (DB::table('whatsapp_templates')->get(['id', 'language', 'acknowledgement']) as $template) {
            $english = str_starts_with((string) $template->language, 'en');

            DB::table('whatsapp_templates')->where('id', $template->id)->update(array_filter([
                'text_reply' => $english ? self::EN_REPLY : self::AR_REPLY,
                // The standard thank-you gains the contact line; one written
                // differently is left as written.
                'acknowledgement' => trim((string) $template->acknowledgement) === self::AR_THANKS ? self::AR_THANKS.self::AR_THANKS_CONTACT : null,
            ]));
        }

        // This office's own details (the address the legal pages showed
        // until now); another installation fills in its own in Settings.
        if (str_contains((string) config('app.url'), 'jpaemirates.com')) {
            $now = now();

            foreach (['company_phone' => '+971 4 328 7778', 'company_whatsapp' => '+971 56 107 5965', 'company_email' => 'info@jpaemirates.com'] as $key => $value) {
                if (! DB::table('settings')->where('key', $key)->exists()) {
                    DB::table('settings')->insert(['key' => $key, 'value' => $value, 'group' => 'general', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            Setting::clearCache();
        }
    }

    public function down(): void
    {
        Schema::table('minutes_deliveries', function (Blueprint $table) {
            $table->dropColumn(['replies', 'text_reply_sent_at']);
        });

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropColumn('text_reply');
        });
    }
};
