<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════
// ملف: database/migrations
// رسوم التسجيل لكل سنة دراسية (Academic Year Fees) - بإذن الله تعالى
// رسوم موحّدة لكل من يسجّل في سنة دراسية معينة، بغض النظر عن البرنامج
// ══════════════════════════════════════════════════════════════

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('academic_year_fees', function (Blueprint $table) {
            $table->id();

            // مثال: "2026-2027"
            $table->string('academic_year')->unique();

            // صفر يعني أن التسجيل في هذه السنة مجاني (لا يوجد خصم)
            $table->decimal('fee', 10, 2)->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_year_fees');
    }
};
