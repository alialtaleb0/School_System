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
        Schema::table('teachers', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('user_id');
            $table->string('profile_image')->nullable()->after('status');
            $table->enum('pending_action', ['none', 'update', 'delete'])->default('none')->after('profile_image');
            $table->json('pending_data')->nullable()->after('pending_action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['status', 'profile_image', 'pending_action', 'pending_data']);
        });
    }
};
