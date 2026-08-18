<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    protected $fillable = ['level_id', 'program_id', 'name', 'capacity'];

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    public function timetables()
    {
        return $this->hasMany(Timetable::class);
    }

    public function homeworks()
    {
        return $this->hasMany(Homework::class);
    }
}
