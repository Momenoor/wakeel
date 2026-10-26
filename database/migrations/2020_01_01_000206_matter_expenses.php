<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is paid out for a matter's procedures — a court fee, a translation … (App\Models\MatterExpense): the money
 * the client gives for them is a fee of type "Matter expenses", collected like any other; this is where it goes.
 * A new table: nothing of before changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('who entered it');
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('payee')->nullable()->comment('paid to: the court, the translator …');
            $table->text('description')->nullable()->comment('what it was paid for');
            $table->timestamps();

            $table->index(['matter_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_expenses');
    }
};
