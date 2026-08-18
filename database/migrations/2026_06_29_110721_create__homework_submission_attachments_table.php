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
        Schema::create('homework_submission_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('homework_submission_id')->constrained()->cascadeOnDelete();

            // مسار الملف داخل storage/app/public
            $table->string('file_path');

            // الاسم الأصلي للملف كما رفعه الطالب
            $table->string('file_name');

            // نوع المرفق: صورة أو ملف عام (pdf, docx, ...)
            $table->enum('file_type', ['image', 'file'])->default('file');

            // نوع المحتوى الأصلي (MIME) وحجم الملف بالبايت لعرضهما في الواجهة
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('homework_submission_attachments');
    }
};
