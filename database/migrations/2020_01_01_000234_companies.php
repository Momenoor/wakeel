<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office's companies (Human Resources → Companies): each one's trade licence — its number, the authority that
 * issued it, its start and expiry — and its establishment card, their expiry watched like the employees' documents
 * (App\Filament\Support\ExpiringDocuments), with their copies kept as its documents (attachments). Seen by whoever
 * may see the employees, kept by whoever may change them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('trade_license_no')->nullable();
            $table->string('license_authority')->nullable()->comment('Who issued the trade licence (DED, a free zone …)');
            $table->date('trade_license_start')->nullable();
            $table->date('trade_license_expiry')->nullable()->index();
            $table->string('establishment_card_no')->nullable();
            $table->date('establishment_card_issue')->nullable();
            $table->date('establishment_card_expiry')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['ViewAny', 'View'] as $ability) {
            ScreenPermissions::grant($ability.':Company', ['ViewAny:EmployeeProfile']);
        }

        foreach (['Create', 'Update', 'Delete'] as $ability) {
            ScreenPermissions::grant($ability.':Company', ['Update:EmployeeProfile']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
