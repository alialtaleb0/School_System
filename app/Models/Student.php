<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'user_id',
        'student_number',
        'parent_id',
        'date_of_birth',
        'profile_image',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function attendance()
    {
        return $this->hasMany(Attendance::class);
    }

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    // current level (اختياري حسب تصميمك)
    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

public function parent() {
    return $this->belongsTo(ParentModel::class, 'parent_id');
}

public function certificates()
{
    return $this->hasMany(\App\Models\Certificate::class);
}

    public function homeworkSubmissions()
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    /**
     * مدرّسو هذا الطالب الفعليون حسب جدول حصصه الحالي (Schedule)، عبر المسار:
     * student_enrollments (status=approved) -> section -> timetable -> schedules -> teacher
     * تُستخدم هذه الدالة لتحديد من يحق للطالب محادثته في نظام المحادثات.
     *
     * ملاحظة: تُسمَّى عمداً scheduledTeachers() وليس teachers()، لأنها لا تُمثّل
     * علاقة Eloquent حقيقية (Relationship) بل استعلاماً محسوباً عبر عدة جداول.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Teacher>
     */
    public function scheduledTeachers()
    {
        $sectionIds = $this->enrollments()
            ->where('status', 'approved')
            ->pluck('section_id');

        if ($sectionIds->isEmpty()) {
            return collect();
        }

        return Teacher::query()
            ->where('status', 'approved')
            ->whereHas('schedules.timetable', function ($query) use ($sectionIds) {
                $query->whereIn('section_id', $sectionIds);
            })
            ->with('user')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * هل يدرّس هذا المدرّس فعلاً عند هذا الطالب حسب الجدول؟
     */
    public function isTaughtBy(Teacher $teacher): bool
    {
        return $this->scheduledTeachers()->contains('id', $teacher->id);
    }
}
