<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matter types are set from the matter type, not from the letter template, so
 * "all types except these" is kept per link: a template linked to a type as
 * `excluded` is not offered for that type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_template_type', function (Blueprint $table) {
            $table->boolean('excluded')->default(false)->comment('The template is NOT offered for this type');
        });

        if (Schema::hasColumn('letter_templates', 'types_except')) {
            DB::table('letter_template_type')
                ->whereIn('letter_template_id', DB::table('letter_templates')->where('types_except', true)->select('id'))
                ->update(['excluded' => true]);

            Schema::table('letter_templates', function (Blueprint $table) {
                $table->dropColumn('types_except');
            });
        }
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->boolean('types_except')->default(false);
        });

        // A template whose links are all exclusions was "all except".
        DB::table('letter_templates')
            ->whereIn('id', DB::table('letter_template_type')->where('excluded', true)->select('letter_template_id'))
            ->whereNotIn('id', DB::table('letter_template_type')->where('excluded', false)->select('letter_template_id'))
            ->update(['types_except' => true]);

        Schema::table('letter_template_type', function (Blueprint $table) {
            $table->dropColumn('excluded');
        });
    }
};
