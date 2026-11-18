<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each browser's own number (kept in it), with its push subscription: a browser that subscribes again (a new
 * address after the move to another server, its data cleared) has its older subscriptions removed - push services
 * went on delivering to them too, and every notification came twice or more (PushSubscriptionController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });
    }
};
