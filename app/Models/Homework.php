<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Homework extends Model
{
    protected $table = 'homeworks';
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'teacher_id',
        'level_id',
        'section_id',
        'title',
        'description',
        'due_date',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function submissions()
    {
        return $this->hasMany(HomeworkSubmission::class);
    }
}
