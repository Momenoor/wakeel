<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The minutes email greeted "السادة/ {{recipient.name}} المحترمين" — and the
 * name came with its own title: "السادة/ الأستاذ/ موزة … المحترمين". It now
 * greets with {{recipient.salutation}}: the title and the honorific that
 * agrees ("الأستاذة/ موزة … المحترمة"). Only the standard wording changes; a
 * template written otherwise is left as it is.
 */
return new class extends Migration
{
    private const OLD = 'السادة/ {{recipient.name}} المحترمين';

    private const NEW = '{{recipient.salutation}}';

    public function up(): void
    {
        foreach (DB::table('email_templates')->where('body', 'like', '%'.self::OLD.'%')->get(['id', 'body']) as $template) {
            DB::table('email_templates')->where('id', $template->id)->update(['body' => str_replace(self::OLD, self::NEW, $template->body)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('email_templates')->where('body', 'like', '%'.self::NEW.'%')->get(['id', 'body']) as $template) {
            DB::table('email_templates')->where('id', $template->id)->update(['body' => str_replace(self::NEW, self::OLD, $template->body)]);
        }
    }
};
