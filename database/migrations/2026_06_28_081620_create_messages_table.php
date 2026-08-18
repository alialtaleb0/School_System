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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // المرسل (يمكن أن يصبح null إذا تم حذف حساب المستخدم، لكن الرسالة تبقى محفوظة)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // نص الرسالة (يمكن أن يكون فارغاً إذا كانت الرسالة مرفقاً فقط)
            $table->text('body')->nullable();

            // نوع الرسالة: نص فقط أو تحتوي مرفقات
            $table->enum('type', ['text', 'attachment'])->default('text');

            // تعديل/حذف ناعم للرسالة (سياسة سلوكية أساسية لأي نظام محادثة)
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
