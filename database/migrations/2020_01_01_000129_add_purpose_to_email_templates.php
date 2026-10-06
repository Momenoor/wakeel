<?php

use App\Services\MMS\Letters\MinutesSender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email templates by purpose: a letter's covering email (all until now), or
 * the email that sends minutes to the attendees for signature — that one
 * was fixed in the code; it starts as a template in each language, the
 * same text, to edit like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('purpose')->default('letter')->after('name')->index();
        });

        $now = now();

        foreach (['ar' => true, 'en' => false] as $locale => $arabic) {
            DB::table('email_templates')->insert([
                'name' => $arabic ? 'إرسال المحضر للتوقيع' : 'Minutes for signature',
                'purpose' => 'minutes_signature',
                'locale' => $locale,
                'subject' => MinutesSender::defaultSubject($arabic),
                'body' => MinutesSender::defaultBody($arabic),
                'is_active' => true,
                'is_default' => $arabic,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->where('purpose', 'minutes_signature')->delete();

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropIndex(['purpose']);
            $table->dropColumn('purpose');
        });
    }
};
