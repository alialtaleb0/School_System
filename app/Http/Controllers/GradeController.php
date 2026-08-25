<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\Exam;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GradeController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * عرض قائمة بالدرجات، مقيّدة حسب الدور:
     * - الأدمن: كل الدرجات.
     * - المعلم: درجات طلابه الفعليين فقط حسب جدوله.
     * - الطالب: درجاته الخاصة فقط.
     * - وليّ الأمر: درجات أبنائه فقط.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'student_id' => 'nullable|exists:students,id',
            'exam_id' => 'nullable|exists:exams,id',
        ]);

        $query = Grade::with(['student.user', 'exam.subject', 'teacher.user']);

        if ($user->role === 'teacher') {
            if (!$user->teacher) {
                return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
            }

            $query->whereIn('student_id', $user->teacher->scheduledStudents()->pluck('id'));
        } elseif ($user->role === 'student') {
            if (!$user->student) {
                return response()->json(['error' => __('No student profile linked to your account.')], 403);
            }

            $query->where('student_id', $user->student->id);
        } elseif ($user->role === 'parent') {
            if (!$user->parent || $user->parent->status !== 'approved') {
                return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
            }

            $query->whereIn('student_id', $user->parent->students()->pluck('id'));
        } elseif ($user->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        if (!empty($data['student_id'])) {
            $query->where('student_id', $data['student_id']);
        }

        if (!empty($data['exam_id'])) {
            $query->where('exam_id', $data['exam_id']);
        }

        $grades = $query->latest()->get();

        return response()->json(['data' => $grades]);
    }

    /**
     * 1️⃣ إضافة درجة جديدة لطالب (معدلة للتحقق من مادة الأستاذ)
     * POST /api/grades
     */
    public function store(Request $request)
    {
        // 1. التأكد من أن المستخدم الحالي هو أستاذ أو أدمن
        $user = auth()->user();
        if (!in_array($user->role, ['teacher', 'admin'])) {
            return response()->json(['error' => __('Only teachers and admins can add grades.')], 403);
        }

        $teacher = $user->role === 'teacher' ? $user->teacher : null;
        if ($user->role === 'teacher' && !$teacher) {
            return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
        }

        // 2. التحقق من البيانات المرسلة
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'exam_id' => [
                'required',
                'exists:exams,id',
                // شرط متقدم: التأكد من أن الامتحان ينتمي لمادة يدرّسها هذا الأستاذ (للأدمن لا يُطبَّق)
                function ($attribute, $value, $fail) use ($teacher) {
                    if (!$teacher) return;
                    $exam = Exam::find($value);
                    if ($exam) {
                        // التحقق من وجود المادة الخاصة بالامتحان داخل جدول subject_teacher للأستاذ الحالي
                        $ownsSubject = \DB::table('subject_teacher')
                            ->where('teacher_id', $teacher->id)
                            ->where('subject_id', $exam->subject_id)
                            ->exists();

                        if (!$ownsSubject) {
                            $fail(__('You cannot add a grade for this exam because its subject is not assigned to you.'));
                        }
                    }
                },
            ],
            'mark' => 'required|numeric|min:0',
        ]);

        // 3. إنشاء سجل الدرجة (مع تخزين معرف الأستاذ الذي قام بالتصحيح/الإدخال)
        $grade = Grade::create([
            'student_id' => $data['student_id'],
            'exam_id'    => $data['exam_id'],
            'teacher_id' => $teacher ? $teacher->id : null, // الأستاذ الحالي هو المصحح، أو null للأدمن
            'mark'       => $data['mark'],
        ]);

        $this->notifications->newGrade($grade);

        return response()->json([
            'message' => __('Grade added successfully'),
            'data' => $grade
        ], 201);
    }

    /**
     * عرض تفاصيل درجة معينة (نفس تقييد index: صاحبها/وليّ أمره/معلمه الفعلي/الأدمن)
     */
    public function show(Grade $grade)
    {
        $grade->load(['student.user', 'exam.subject', 'teacher.user']);
        $user = auth()->user();

        $isAuthorized = match (true) {
            $user->role === 'admin' => true,
            $user->role === 'teacher' => (bool) ($user->teacher && $user->teacher->teaches($grade->student)),
            $user->role === 'student' => (bool) ($user->student && $user->student->id === $grade->student_id),
            $user->role === 'parent' => (bool) (
                $user->parent
                && $user->parent->status === 'approved'
                && $grade->student->parent_id === $user->parent->id
            ),
            default => false,
        };

        if (!$isAuthorized) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json(['data' => $grade]);
    }

    /**
     * 2️⃣ تعديل درجة طالب (معدلة للتحقق من الصلاحية والمادة)
     * PUT/PATCH /api/grades/{id}
     */
    public function update(Request $request, Grade $grade)
    {
        $user = auth()->user();
        if (!in_array($user->role, ['teacher', 'admin'])) {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $teacher = $user->role === 'teacher' ? $user->teacher : null;
        if ($user->role === 'teacher' && !$teacher) {
            return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
        }

        // التأكد من أن الأستاذ الذي يحاول التعديل يدرّس مادة هذا الامتحان (للأدمن لا يُطبَّق)
        if ($teacher) {
            $exam = $grade->exam;
            if (!$exam) {
                return response()->json(['error' => __('The exam linked to this grade does not exist.')], 422);
            }
            $ownsSubject = \DB::table('subject_teacher')
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $exam->subject_id)
                ->exists();

            if (!$ownsSubject) {
                return response()->json(['error' => __('You cannot edit this grade because the exam subject is not assigned to you.')], 403);
            }
        }

        // التحقق من البيانات الجديدة في حال تم تعديل الامتحان أو العلامة
        $data = $request->validate([
            'exam_id' => [
                'sometimes',
                'required',
                'exists:exams,id',
                function ($attribute, $value, $fail) use ($teacher) {
                    if (!$teacher) return;
                    $newExam = Exam::find($value);
                    if ($newExam) {
                        $ownsNewSubject = \DB::table('subject_teacher')
                            ->where('teacher_id', $teacher->id)
                            ->where('subject_id', $newExam->subject_id)
                            ->exists();

                        if (!$ownsNewSubject) {
                            $fail(__('The selected new exam belongs to a subject you do not teach.'));
                        }
                    }
                },
            ],
            'mark' => 'sometimes|required|numeric|min:0',
        ]);

        $grade->update($data);

        return response()->json([
            'message' => __('Grade updated successfully'),
            'data' => $grade
        ]);
    }

    /**
     * حذف درجة
     */
    public function destroy(Grade $grade)
    {
        $user = auth()->user();
        if (!in_array($user->role, ['teacher', 'admin'])) {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $teacher = $user->role === 'teacher' ? $user->teacher : null;
        if ($user->role === 'teacher' && !$teacher) {
            return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
        }

        // التحقق من أن مادة الامتحان تابعة للأستاذ قبل الحذف (للأدمن لا يُطبَّق)
        if ($teacher) {
            $exam = $grade->exam;
            if ($exam) {
                $ownsSubject = \DB::table('subject_teacher')
                    ->where('teacher_id', $teacher->id)
                    ->where('subject_id', $exam->subject_id)
                    ->exists();

                if (!$ownsSubject) {
                    return response()->json(['error' => __('You do not have permission to delete this grade.')], 403);
                }
            }
        }

        $grade->delete();

        return response()->json(['message' => __('Grade deleted successfully')]);
    }
}
