<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = [
        'timetable_id',
        'subject_id',
        'teacher_id',
        'day',
        'period',
    ];

    public function timetable()
    {
        return $this->belongsTo(Timetable::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
