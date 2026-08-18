<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    protected $fillable = ['name'];

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'level_subject')->withPivot('type')->withTimestamps();
    }

    public function sections()
    {
        return $this->hasMany(Section::class);
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    public function homeworks()
    {
        return $this->hasMany(Homework::class);
    }
}
