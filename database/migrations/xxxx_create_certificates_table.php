<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();

            // ربط بالطالب
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // ربط بالتسجيل لمعرفة السنة / المرحلة / الشعبة
            $table->foreignId('enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();

            $table->string('academic_year');   // مثلاً: 2025-2026
            $table->string('level');           // اسم المرحلة (يُحفظ نصاً لثبات الوثيقة)
            $table->string('section')->nullable(); // اسم الشعبة

            // المعدل النهائي — يُحسب تلقائياً من grades
            $table->float('final_grade');

            // الحالة بناءً على المعدل
            $table->enum('status', ['ناجح', 'راسب', 'متفوق']);

            // للتحقق عبر QR
            $table->string('token')->unique();
            $table->string('qr_code_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
