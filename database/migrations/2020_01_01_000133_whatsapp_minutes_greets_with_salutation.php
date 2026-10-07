<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The original office's minutes template is approved in Meta with its new
 * first line, "إلى {{name}}،" — {{name}} now filled with the greeting that
 * agrees with the title ("الأستاذة/ … المحترمة") instead of the bare name
 * inside a fixed "السادة/ … المحترمين".
 *
 * Only there, and only while it still has the old wording: another office's
 * template in Meta keeps its own until it is changed and approved (then its
 * {{name}} parameter is set to {{recipient.salutation}} by hand).
 */
return new class extends Migration
{
    private const OLD_FIRST_LINE = 'السادة/ {{name}} المحترمين،';

    private const NEW_FIRST_LINE = 'إلى {{name}}،';

    public function up(): void
    {
        if (! str_contains((string) config('app.url'), 'jpaemirates.com')) {
            return;
        }

        $templates = DB::table('whatsapp_templates')
            ->where('meta_name', 'minutes_for_signature')
            ->where('body', 'like', self::OLD_FIRST_LINE.'%')
            ->get(['id', 'body', 'parameters']);

        foreach ($templates as $template) {
            $parameters = array_map(
                fn (array $p): array => ($p['name'] ?? null) === 'name' && ($p['value'] ?? null) === '{{recipient.name}}'
                    ? [...$p, 'value' => '{{recipient.salutation}}']
                    : $p,
                (array) json_decode((string) $template->parameters, true),
            );

            DB::table('whatsapp_templates')->where('id', $template->id)->update([
                'body' => self::NEW_FIRST_LINE.substr($template->body, strlen(self::OLD_FIRST_LINE)),
                'parameters' => json_encode($parameters, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // The template in Meta decides; nothing to put back here.
    }
};
