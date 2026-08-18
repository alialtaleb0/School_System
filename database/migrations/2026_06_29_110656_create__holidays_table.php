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
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // مثال: "عيد الفطر"، "العطلة الشتوية"
            $table->date('from_date');
            $table->date('to_date'); // ليوم واحد فقط: from_date = to_date
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // الأدمن الذي أضافها
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
