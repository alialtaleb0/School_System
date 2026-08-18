<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
{
    Schema::table('parents', function (Blueprint $table) {
        // حالة الحساب: معلق (pending)، مقبول (approved)، مرفوض (rejected)
        $table->string('status')->default('pending')->after('user_id');

        // تخزين الـ IDs للطلاب الذين طلب الأب ربطهم كـ نص JSON مؤقت لحين موافقة الأدمن
        $table->json('pending_student_ids')->nullable()->after('status');
    });
}

public function down(): void
{
    Schema::table('parents', function (Blueprint $table) {
        $table->dropColumn(['status', 'pending_student_ids']);
    });
}
};
