<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class HomeworkSubmissionAttachment extends Model
{
    protected $fillable = [
        'homework_submission_id',
        'file_path',
        'file_name',
        'file_type',
        'mime_type',
        'file_size',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(HomeworkSubmission::class, 'homework_submission_id');
    }

    /**
     * رابط مباشر وصالح لعرض/تنزيل الملف (يفترض استخدام القرص العام "public")
     */
    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
