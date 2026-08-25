<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->string('type')->default('email_verify')->after('user_id');
            $table->index(['user_id', 'type', 'is_used']);
        });
    }

    public function down(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type', 'is_used']);
            $table->dropColumn('type');
        });
    }
};
