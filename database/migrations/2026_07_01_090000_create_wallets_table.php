<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════
// ملف: database/migrations
// نظام محفظة ولي الأمر (Wallet) - بإذن الله تعالى
// محفظة واحدة لكل ولي أمر، تُستخدم لدفع رسوم التسجيل ولأي رصيد عام آخر
// ══════════════════════════════════════════════════════════════

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            // كل ولي أمر له محفظة واحدة فقط
            $table->foreignId('parent_id')
                  ->unique()
                  ->constrained('parents')
                  ->cascadeOnDelete();

            // الرصيد الحالي للمحفظة (يُحدَّث دائماً مع كل عملية داخل transaction آمنة)
            $table->decimal('balance', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
