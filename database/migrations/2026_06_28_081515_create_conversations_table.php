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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            // نوع المحادثة: ثنائية (direct) أو جماعية (group)
            $table->enum('type', ['direct', 'group'])->default('direct');

            // اسم المجموعة (يُستخدم فقط مع type = group)
            $table->string('name')->nullable();

            // صاحب إنشاء المحادثة (المدرّس أو الأدمن في حال المجموعات)
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // آخر وقت إرسال رسالة، لتسهيل ترتيب قائمة المحادثات دون الحاجة لـ JOIN ثقيل
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            $table->index(['type', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
