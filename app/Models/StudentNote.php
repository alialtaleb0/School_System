<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentNote extends Model
{
    protected $fillable = [
        'student_id',
        'teacher_id',
        'title',
        'body',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(StudentNoteReply::class)->orderBy('created_at');
    }
}
