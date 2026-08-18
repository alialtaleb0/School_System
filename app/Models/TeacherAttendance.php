<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherAttendance extends Model
{
    protected $table = 'teacher_attendances';

    protected $fillable = [
        'teacher_id',
        'date',
        'status',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
