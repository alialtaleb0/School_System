<?php

// ══════════════════════════════════════════════════════════════
// ملف: database/migrations/2026_07_02_100000_create_notifications_table.php
// نظام الإشعارات الداخلية (In-App Notifications) - بإذن الله تعالى
// جدول قياسي متوافق مع نظام Notifications المدمج في Laravel
// (نفس الجدول الذي كان سيُنشأ عبر: php artisan notifications:table)
// ══════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            // notifiable_type + notifiable_id => يشير دائماً إلى App\Models\User في مشروعنا
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
