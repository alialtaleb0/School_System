<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

class ConversationPolicy
{
    /**
     * هل يحق لـ $actor بدء محادثة ثنائية (Direct) مع $target؟
     */
    public function canStartDirect(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        if ($actor->role === 'admin' || $target->role === 'admin') {
            return true;
        }

        return match (true) {
            $actor->role === 'student' && $target->role === 'teacher'
                => $this->studentCanReachTeacher($actor, $target),

            $actor->role === 'teacher' && $target->role === 'student'
                => $this->studentCanReachTeacher($target, $actor),

            $actor->role === 'parent' && $target->role === 'teacher'
                => $this->parentCanReachTeacher($actor, $target),

            $actor->role === 'teacher' && $target->role === 'parent'
                => $this->parentCanReachTeacher($target, $actor),

            default => false,
        };
    }

    /**
     * هل يحق لـ $actor إنشاء محادثة جماعية؟
     * فقط المدرّس والأدمن يحق لهما ذلك.
     */
    public function canCreateGroup(User $actor): bool
    {
        return in_array($actor->role, ['teacher', 'admin'], true);
    }

    /**
     * هل يحق لـ $actor إضافة $target كعضو في مجموعة؟
     */
    public function canAddToGroup(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        if ($actor->role === 'admin') {
            return true;
        }

        if ($actor->role === 'teacher') {
            if ($target->role === 'student') {
                return $this->studentCanReachTeacher($target, $actor);
            }

            if ($target->role === 'parent') {
                return $this->parentCanReachTeacher($target, $actor);
            }

            return false;
        }

        return false;
    }

    /**
     * هل يحق لـ $user الوصول لمحادثة معيّنة؟
     *
     * الشرط الأساسي: أن يكون مشاركاً نشطاً فيها.
     *
     * 🔒 قيد إضافي للأب:
     * الأب يحق له فقط الوصول للمحادثات التي هو طرف فيها مع معلم —
     * أي المحادثات التي لا يوجد فيها طالب من أبنائه كمشارك آخر.
     * بمعنى آخر: الأب لا يستطيع الدخول لمحادثة ابنه مع المعلم،
     * بل فقط لمحادثته هو الخاصة مع المعلم.
     */
    public function canAccessConversation(User $user, Conversation $conversation): bool
    {
        // الشرط الأساسي: يجب أن يكون مشاركاً نشطاً
        $isParticipant = $conversation->activeParticipants()
            ->where('users.id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return false;
        }

        // 🔒 قيد خاص بالأب
        if ($user->role === 'parent') {
            $parent = $user->parent;

            if (! $parent) {
                return false;
            }

            // جلب IDs أبنائه
            $childUserIds = $parent->students()
                ->join('users', 'users.id', '=', 'students.user_id')
                ->pluck('users.id')
                ->toArray();

            if (empty($childUserIds)) {
                return true; // ما عنده أبناء مسجلين → لا قيد
            }

            // إذا كان أحد أبنائه مشاركاً في هذه المحادثة → لا يحق للأب الدخول
            $childIsParticipant = $conversation->activeParticipants()
                ->whereIn('users.id', $childUserIds)
                ->exists();

            if ($childIsParticipant) {
                return false; // 🚫 هذه محادثة ابنه، مش محادثته هو
            }
        }

        return true;
    }

    /**
     * هل يدرّس هذا المدرّس هذا الطالب فعلاً حسب الجدول؟
     */
    protected function studentCanReachTeacher(User $studentUser, User $teacherUser): bool
    {
        $student = $studentUser->student;
        $teacher = $teacherUser->teacher;

        if (! $student instanceof Student || ! $teacher instanceof Teacher) {
            return false;
        }

        if ($teacher->status !== 'approved') {
            return false;
        }

        return $student->isTaughtBy($teacher);
    }

    /**
     * هل يدرّس هذا المدرّس أحد أبناء هذا الأب فعلاً حسب الجدول؟
     */
    protected function parentCanReachTeacher(User $parentUser, User $teacherUser): bool
    {
        $parent = $parentUser->parent;
        $teacher = $teacherUser->teacher;

        if (! $parent || ! $teacher instanceof Teacher) {
            return false;
        }

        if ($parent->status !== 'approved') {
            return false;
        }

        if ($teacher->status !== 'approved') {
            return false;
        }

        return $parent->hasTeacherForAnyChild($teacher);
    }
}
