<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [
        'name',
    ];

    public function levels()
    {
        return $this->belongsToMany(Level::class, 'level_subject')->withPivot('type')->withTimestamps();
    }

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'program_subject');
    }

    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'subject_teacher');
    }

    public function exams()
    {
        return $this->hasMany(Exam::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function homeworks()
    {
        return $this->hasMany(Homework::class);
    }
}
