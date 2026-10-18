<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each user is told of, and by which channel: a role's defaults (on, off, mandatory, or not
 * offered), and each user's own choices over them. The two switches users had ("notify by email" and
 * "notify by WhatsApp"), which almost nothing consulted, become choices here: email off for everything
 * where it was off, WhatsApp on for the new requests where it was on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_notification_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('channel', 20);
            $table->string('state', 10)->comment('on | off | locked (mandatory) | hidden (not offered)');
            $table->timestamps();

            $table->unique(['role_id', 'type', 'channel']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('channel', 20);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'type', 'channel']);
        });

        $now = now();
        $rows = [];

        foreach (DB::table('users')->select(['id', 'notify_by_email', 'notify_by_whatsapp'])->get() as $user) {
            foreach (NotificationType::cases() as $type) {
                if (! $user->notify_by_email && $type->supports(NotificationChannel::MAIL) && $type->isOnByDefault(NotificationChannel::MAIL)) {
                    $rows[] = ['user_id' => $user->id, 'type' => $type->value, 'channel' => 'mail', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            if ($user->notify_by_whatsapp) {
                $rows[] = ['user_id' => $user->id, 'type' => NotificationType::REQUEST_CREATED->value, 'channel' => 'whatsapp', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('notification_preferences')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('role_notification_defaults');
    }
};
