<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'profile_image',
        'pending_action',
        'pending_data',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'subject_teacher');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }


    /**
     * سجلات حضور وغياب هذا المعلم
     */
    public function attendances()
    {
        return $this->hasMany(TeacherAttendance::class);
    }

    public function exams()
    {
        return $this->hasMany(Exam::class);
    }

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    public function homeworks()
    {
        return $this->hasMany(Homework::class);
    }

    /**
     * طلاب هذا المدرّس الفعليون حسب جدوله الحالي (Schedule)، عبر المسار:
     * schedules -> timetable -> section -> student_enrollments (status=approved) -> student
     * تُستخدم هذه الدالة لتحديد من يحق للمدرّس محادثته (طلاب أو إنشاء مجموعة معهم).
     *
     * ملاحظة: تُسمَّى عمداً scheduledStudents() وليس students()، لأنها لا تُمثّل
     * علاقة Eloquent حقيقية (Relationship) بل استعلاماً محسوباً عبر عدة جداول،
     * فلا يصح استخدامها كخاصية ديناميكية ($teacher->students) بل كدالة فقط.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Student>
     */
    public function scheduledStudents()
    {
        $sectionIds = $this->schedules()
            ->with('timetable')
            ->get()
            ->pluck('timetable.section_id')
            ->filter()
            ->unique();

        if ($sectionIds->isEmpty()) {
            return collect();
        }

        return Student::query()
            ->whereHas('enrollments', function ($query) use ($sectionIds) {
                $query->where('status', 'approved')
                    ->whereIn('section_id', $sectionIds);
            })
            ->with('user')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * طلاب هذا المدرّس مجمّعين حسب الشُعبة (Section)، لعرضهم عند إنشاء مجموعة جديدة
     * يدوياً من قِبل المدرّس (واجهة اختيار يدوي للأعضاء).
     *
     * المفتاح هو section_id والقيمة هي مجموعة الطلاب في تلك الشعبة.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection>
     */
    public function studentsBySection()
    {
        $sectionIds = $this->schedules()
            ->with('timetable')
            ->get()
            ->pluck('timetable.section_id')
            ->filter()
            ->unique()
            ->values();

        $grouped = collect();

        foreach ($sectionIds as $sectionId) {
            $students = Student::query()
                ->whereHas('enrollments', function ($query) use ($sectionId) {
                    $query->where('status', 'approved')->where('section_id', $sectionId);
                })
                ->with('user')
                ->get();

            if ($students->isNotEmpty()) {
                $grouped->put($sectionId, $students);
            }
        }

        return $grouped;
    }

    /**
     * هل هذا الطالب أحد طلاب هذا المدرّس فعلاً حسب الجدول؟
     */
    public function teaches(Student $student): bool
    {
        return $this->scheduledStudents()->contains('id', $student->id);
    }
}
