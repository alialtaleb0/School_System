<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

class StudentProgressPolicy
{
    /**
     * هل يحق لـ $user الاطلاع على تقرير مقارنة تقدّم $student؟
     *
     * القواعد:
     * - الأدمن يشاهد تقدّم أي طالب بلا قيود.
     * - الطالب يشاهد تقدّمه هو فقط.
     * - الأب يشاهد تقدّم أحد أبنائه فقط (عبر علاقة parent_id).
     * - المدرّس يشاهد تقدّم الطالب فقط إذا كان يدرّسه فعلاً حسب الجدول (نفس منطق isTaughtBy).
     */
    public function canView(User $user, Student $student): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return match ($user->role) {
            'student' => $this->isSameStudent($user, $student),
            'parent' => $this->isParentOf($user, $student),
            'teacher' => $this->teacherTeachesStudent($user, $student),
            default => false,
        };
    }

    /**
     * هل هذا المستخدم هو نفس الطالب المطلوب؟
     */
    protected function isSameStudent(User $user, Student $student): bool
    {
        $owned = $user->student;

        return $owned instanceof Student && $owned->id === $student->id;
    }

    /**
     * هل هذا المستخدم هو ولي أمر هذا الطالب؟
     */
    protected function isParentOf(User $user, Student $student): bool
    {
        $parent = $user->parent;

        if (! $parent) {
            return false;
        }

        return $parent->students()->where('id', $student->id)->exists();
    }

    /**
     * هل هذا المدرّس يدرّس هذا الطالب فعلاً حسب الجدول الحالي؟
     */
    protected function teacherTeachesStudent(User $user, Student $student): bool
    {
        $teacher = $user->teacher;

        if (! $teacher instanceof Teacher || $teacher->status !== 'approved') {
            return false;
        }

        return $teacher->teaches($student);
    }
}
