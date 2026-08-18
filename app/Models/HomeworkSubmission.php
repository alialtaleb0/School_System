<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeworkSubmission extends Model
{
    protected $fillable = [
        'homework_id',
        'student_id',
        'status',
        'submitted_at',
        'mark',
        'feedback',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function homework()
    {
        return $this->belongsTo(Homework::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function attachments()
    {
        return $this->hasMany(HomeworkSubmissionAttachment::class);
    }
}
