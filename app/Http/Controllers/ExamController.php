<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * 1️⃣ عرض قائمة بجميع الامتحانات
     */
    public function index()
    {
        $exams = Exam::with(['subject', 'teacher.user'])->latest()->get();

        return response()->json([
            'message' => __('Exams retrieved successfully'),
            'data' => $exams
        ]);
    }

    /**
     * 2️⃣ إنشاء امتحان جديد (معدلة للتحقق من مادة الأستاذ)
     * POST /api/exams
     */
    public function store(Request $request)
    {
        // 1. التحقق من صلاحية الأستاذ أو الأدمن
        $user = auth()->user();
        if (!in_array($user->role, ['teacher', 'admin'])) {
            return response()->json(['error' => __('Only teachers and admins can create exams.')], 403);
        }

        $teacher = $user->role === 'teacher' ? $user->teacher : null;
        if ($user->role === 'teacher' && !$teacher) {
            return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
        }

        // 2. التحقق من البيانات مع شرط أن تكون المادة تابعة للأستاذ الحالي (للأدمن لا يُطبَّق)
        $subjectRules = ['required', 'exists:subjects,id'];
        if ($teacher) {
            $subjectRules[] = Rule::exists('subject_teacher', 'subject_id')->where(function ($query) use ($teacher) {
                $query->where('teacher_id', $teacher->id);
            });
        }
        $data = $request->validate([
            'subject_id' => $subjectRules,
            'name'       => 'required|string|max:255',
            'start_time' => 'required|date|after:now',
            'end_time'   => 'nullable|date|after:start_time',
        ], [
            // رسالة خطأ مخصصة باللغة العربية
            'subject_id.exists' => 'المادة المحددة غير موجودة أو غير مسندة إليك لتدريسها.',
        ]);

        // 3. إنشاء الامتحان
        $exam = Exam::create([
            'subject_id' => $data['subject_id'],
            'teacher_id' => $teacher ? $teacher->id : null,
            'name'       => $data['name'],
            'start_time' => $data['start_time'],
            'end_time'   => $data['end_time'],
        ]);

        // إشعار طلاب هذا المعلم (حسب جدوله الفعلي) بالامتحان الجديد
        if ($teacher) {
            $this->notifications->newExam($exam, $teacher->scheduledStudents());
        }

        return response()->json([
            'message' => __('Exam created successfully'),
            'data' => $exam
        ], 201);
    }

    /**
     * 3️⃣ عرض امتحان معين بالتفصيل
     */
    public function show(Exam $exam)
    {
        $exam->load(['subject', 'teacher.user', 'grades.student.user']);

        return response()->json([
            'message' => __('Exam details retrieved successfully'),
            'data' => $exam
        ]);
    }

    /**
     * 4️⃣ تعديل بيانات امتحان معين (معدلة للتحقق من المادة أيضاً)
     * PUT/PATCH /api/exams/{id}
     */
    public function update(Request $request, Exam $exam)
    {
        // السماح للأدمن، وللأستاذ إن كانت مادة الامتحان مسندة إليه
        $user = auth()->user();
        if (!in_array($user->role, ['teacher', 'admin'])) {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $teacher = $user->role === 'teacher' ? $user->teacher : null;
        if ($user->role === 'teacher' && !$teacher) {
            return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
        }

        if ($teacher) {
            $ownsSubject = \DB::table('subject_teacher')
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $exam->subject_id)
                ->exists();

            if (!$ownsSubject) {
                return response()->json(['error' => __('You cannot edit this exam because its subject is not assigned to you.')], 403);
            }
        }

        $subjectRules = ['sometimes', 'required', 'exists:subjects,id'];
        if ($teacher) {
            // التأكد أن المادة الجديدة (في حال تعديلها) تابعة أيضاً للأستاذ
            $subjectRules[] = Rule::exists('subject_teacher', 'subject_id')->where(function ($query) use ($teacher) {
                $query->where('teacher_id', $teacher->id);
            });
        }
        $data = $request->validate([
            'subject_id' => $subjectRules,
            'name'       => 'sometimes|required|string|max:255',
            'start_time' => 'sometimes|required|date',
            'end_time'   => 'nullable|date|after:start_time',
        ], [
            'subject_id.exists' => 'المادة المحددة غير مسندة إليك، لا يمكنك تحويل الامتحان إليها.',
        ]);

        $exam->update($data);

        return response()->json([
            'message' => __('Exam updated successfully'),
            'data' => $exam
        ]);
    }

    /**
     * 5️⃣ حذف امتحان
     */
    public function destroy(Exam $exam)
    {
        $user = auth()->user();
        if (!in_array($user->role, ['teacher', 'admin'])) {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $teacher = $user->role === 'teacher' ? $user->teacher : null;
        if ($user->role === 'teacher' && !$teacher) {
            return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
        }

        if ($teacher) {
            $ownsSubject = \DB::table('subject_teacher')
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $exam->subject_id)
                ->exists();

            if (!$ownsSubject) {
                return response()->json(['error' => __('You do not have permission to delete this exam.')], 403);
            }
        }

        $exam->delete();

        return response()->json([
            'message' => __('Exam deleted successfully')
        ]);
    }

    /**
     * عرض الامتحانات القادمة
     */
    public function upcomingExams()
    {
        $upcoming = Exam::with(['subject', 'teacher.user'])
            ->where('start_time', '>', now())
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'message' => __('Upcoming exams retrieved successfully'),
            'data' => $upcoming
        ]);
    }
}
