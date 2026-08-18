<?php

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
        Schema::table('students', function (Blueprint $table) {
            // إضافة حقل parent_id وجعله يقبل null في حال لم يتم ربط الطالب بولي أمر بعد
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('parents')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // حذف المفتاح الأجنبي ثم الحقل
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
