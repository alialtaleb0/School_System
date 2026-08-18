<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════
// ملف: database/migrations
// سجل حركات المحفظة (Wallet Transactions) - بإذن الله تعالى
// يحفظ كل عملية إيداع/دفع/تعديل مع الرصيد بعدها لضمان إمكانية التدقيق لاحقاً
// ══════════════════════════════════════════════════════════════

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                  ->constrained('wallets')
                  ->cascadeOnDelete();

            // نوع الحركة:
            // deposit    -> إيداع رصيد للمحفظة
            // withdrawal -> سحب/خصم يدوي من الأدمن (تصحيح)
            // payment    -> دفع تلقائي (مثل رسوم تسجيل)
            // refund     -> استرجاع مبلغ سبق دفعه
            $table->enum('type', ['deposit', 'withdrawal', 'payment', 'refund'])->default('deposit');

            // المبلغ دائماً موجب، والاتجاه (زيادة/نقصان) يُحدَّد حسب النوع أعلاه
            $table->decimal('amount', 12, 2);

            // رصيد المحفظة بعد تنفيذ هذه العملية مباشرة (لأغراض التدقيق والسجل)
            $table->decimal('balance_after', 12, 2);

            // طريقة تنفيذ العملية: يدوي من الأدمن الآن، وقابلة للتوسع لبوابة دفع لاحقاً
            $table->enum('method', ['manual', 'gateway'])->default('manual');

            $table->string('description')->nullable();

            // من قام بتنفيذ العملية (الأدمن عند العمليات اليدوية)، يبقى السجل حتى لو حُذف المستخدم
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            // ربط اختياري بمصدر العملية، مثل طلب تسجيل طالب (student_enrollments)
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
