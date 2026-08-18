<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendances';

    protected $fillable = [
        'student_id',
        'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
