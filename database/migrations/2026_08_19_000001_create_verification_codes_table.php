<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 6);
            $table->integer('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_used']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
