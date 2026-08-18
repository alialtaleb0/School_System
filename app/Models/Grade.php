<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = ['student_id', 'exam_id', 'teacher_id', 'mark'];
      public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
