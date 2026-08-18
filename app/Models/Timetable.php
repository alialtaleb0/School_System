<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Timetable extends Model
{
    protected $fillable = [
        'section_id',
        'name',
        'start_date',
        'end_date',
    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function students()
    {
        return $this->hasMany(StudentEnrollment::class);
    }
}
