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
        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->id();
           $table->foreignId('homework_id')->constrained('homeworks')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // pending: لم يسلّم بعد | submitted: سلّم في الوقت | late: سلّم متأخراً | graded: تم تقييمه
            $table->enum('status', ['pending', 'submitted', 'late', 'graded'])->default('pending');

            $table->timestamp('submitted_at')->nullable();
            $table->float('mark')->nullable(); // علامة الواجب يضعها المعلم
            $table->text('feedback')->nullable(); // ملاحظة المعلم على التسليم

            $table->timestamps();

            // كل طالب له تسليم واحد فقط لكل واجب
            $table->unique(['homework_id', 'student_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('homework_submissions');
    }
};
