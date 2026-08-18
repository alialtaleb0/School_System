<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $fillable = ['subject_id', 'teacher_id', 'name', 'start_time', 'end_time'];
       public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }
}
