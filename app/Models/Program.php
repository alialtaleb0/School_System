<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'duration'];
       public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'program_subject');
    }

    public function students()
    {
        return $this->hasMany(StudentEnrollment::class);
    }
}
