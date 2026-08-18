<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\ParentModel;
use App\Models\Teacher;
use App\Policies\StudentProgressPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class StudentProgressController extends Controller
{
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 1️⃣  GET /api/students/{id}/progress-comparison
    //     الطالب نفسه / معلمه / أبوه / الأدمن
    //     (موجود من قبل — تم الإبقاء عليه كما هو)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    public function compare(Request $request, $id): JsonResponse
    {
        $student = Student::with('user')->findOrFail($id);

        if (! (new StudentProgressPolicy())->canView($request->user(), $student)) {
            return response()->json([
                'error' => __('You are not authorized to view this student progress.'),
            ], 403);
        }

        $data = $request->validate([
            'period1_from' => 'required|date',
            'period1_to'   => 'required|date|after_or_equal:period1_from',
            'period2_from' => 'required|date',
            'period2_to'   => 'required|date|after_or_equal:period2_from',
            'subject_id'   => 'nullable|exists:subjects,id',
        ]);

        $subjectId = $data['subject_id'] ?? null;

        $period1 = $this->buildPeriodReport(
            $student,
            Carbon::parse($data['period1_from'])->startOfDay(),
            Carbon::parse($data['period1_to'])->endOfDay(),
            $subjectId
        );

        $period2 = $this->buildPeriodReport(
            $student,
            Carbon::parse($data['period2_from'])->startOfDay(),
            Carbon::parse($data['period2_to'])->endOfDay(),
            $subjectId
        );

        return response()->json([
            'message' => __('Progress comparison generated successfully'),
            'data' => [
                'student'    => ['id' => $student->id, 'name' => $student->user?->name],
                'subject_id' => $subjectId,
                'period_1'   => $period1,
                'period_2'   => $period2,
                'comparison' => $this->buildComparison($period1, $period2),
            ],
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 2️⃣  GET /api/parent/children-progress
    //     ولي الأمر يرى تقدّم جميع أبنائه دفعةً واحدة
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    public function childrenProgress(Request $request): JsonResponse
    {
        // ─ التحقق من الدور ────────────────────────────────────
        if ($request->user()->role !== 'parent') {
            return response()->json(['error' => __('This endpoint is for parents only.')], 403);
        }

        $parent = $request->user()->parent;

        if (! $parent) {
            return response()->json(['error' => __('The parent profile has not been created yet.')], 404);
        }

        if ($parent->status !== 'approved') {
            return response()->json(['error' => __('Your account has not been approved by the admin yet.')], 403);
        }

        // ─ التحقق من الفترات ─────────────────────────────────
        $data = $request->validate([
            'period1_from' => 'required|date',
            'period1_to'   => 'required|date|after_or_equal:period1_from',
            'period2_from' => 'required|date',
            'period2_to'   => 'required|date|after_or_equal:period2_from',
            'subject_id'   => 'nullable|exists:subjects,id',
        ]);

        $subjectId = $data['subject_id'] ?? null;

        // ─ بناء التقرير لكل ابن ──────────────────────────────
        $children = $parent->students()->with('user')->get();

        if ($children->isEmpty()) {
            return response()->json([
                'message' => __('No children linked to this account.'),
                'data'    => [],
            ]);
        }

        $results = $children->map(function (Student $child) use ($data, $subjectId) {
            $period1 = $this->buildPeriodReport(
                $child,
                Carbon::parse($data['period1_from'])->startOfDay(),
                Carbon::parse($data['period1_to'])->endOfDay(),
                $subjectId
            );
            $period2 = $this->buildPeriodReport(
                $child,
                Carbon::parse($data['period2_from'])->startOfDay(),
                Carbon::parse($data['period2_to'])->endOfDay(),
                $subjectId
            );

            return [
                'student'    => ['id' => $child->id, 'name' => $child->user?->name],
                'period_1'   => $period1,
                'period_2'   => $period2,
                'comparison' => $this->buildComparison($period1, $period2),
            ];
        });

        return response()->json([
            'message'    => __('Children progress'),
            'subject_id' => $subjectId,
            'data'       => $results,
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 3️⃣  GET /api/teacher/students-progress
    //     المدرس يرى قائمة طلابه + تقدّم كل واحد
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    public function myStudentsProgress(Request $request): JsonResponse
    {
        // ─ التحقق من الدور ────────────────────────────────────
        if ($request->user()->role !== 'teacher') {
            return response()->json(['error' => __('This endpoint is for teachers only.')], 403);
        }

        $teacher = $request->user()->teacher;

        if (! $teacher || $teacher->status !== 'approved') {
            return response()->json(['error' => __('Your account has not been approved by the admin yet.')], 403);
        }

        // ─ التحقق من الفترات ─────────────────────────────────
        $data = $request->validate([
            'period1_from' => 'required|date',
            'period1_to'   => 'required|date|after_or_equal:period1_from',
            'period2_from' => 'required|date',
            'period2_to'   => 'required|date|after_or_equal:period2_from',
            'subject_id'   => 'nullable|exists:subjects,id',
            // اختياري: تصفية بطالب واحد
            'student_id'   => 'nullable|exists:students,id',
        ]);

        $subjectId = $data['subject_id'] ?? null;

        // ─ طلاب المدرس الفعليون ───────────────────────────────
        $students = $teacher->scheduledStudents();

        if ($students->isEmpty()) {
            return response()->json([
                'message' => __('No students linked to your current schedule.'),
                'data'    => [],
            ]);
        }

        // إذا طلب المدرس طالباً واحداً بالتحديد
        if (! empty($data['student_id'])) {
            $students = $students->where('id', (int) $data['student_id']);

            if ($students->isEmpty()) {
                return response()->json(['error' => __('This student is not one of your students.')], 403);
            }
        }

        // ─ بناء التقرير لكل طالب ─────────────────────────────
        $results = $students->map(function (Student $student) use ($data, $subjectId) {
            $period1 = $this->buildPeriodReport(
                $student,
                Carbon::parse($data['period1_from'])->startOfDay(),
                Carbon::parse($data['period1_to'])->endOfDay(),
                $subjectId
            );
            $period2 = $this->buildPeriodReport(
                $student,
                Carbon::parse($data['period2_from'])->startOfDay(),
                Carbon::parse($data['period2_to'])->endOfDay(),
                $subjectId
            );

            return [
                'student'    => ['id' => $student->id, 'name' => $student->user?->name],
                'period_1'   => $period1,
                'period_2'   => $period2,
                'comparison' => $this->buildComparison($period1, $period2),
            ];
        })->values();

        return response()->json([
            'message'    => __('Your students progress'),
            'subject_id' => $subjectId,
            'total'      => $results->count(),
            'data'       => $results,
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 4️⃣  GET /api/teacher/students-progress/{student}
    //     المدرس يرى تقدّم طالب واحد بالتفصيل
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    public function oneStudentProgress(Request $request, Student $student): JsonResponse
    {
        if ($request->user()->role !== 'teacher') {
            return response()->json(['error' => __('This endpoint is for teachers only.')], 403);
        }

        $teacher = $request->user()->teacher;

        if (! $teacher || $teacher->status !== 'approved') {
            return response()->json(['error' => __('Your account has not been approved by the admin yet.')], 403);
        }

        // التأكد أن الطالب يخص هذا المدرس فعلاً
        if (! $teacher->teaches($student)) {
            return response()->json(['error' => __('This student is not among your students according to the schedule.')], 403);
        }

        $data = $request->validate([
            'period1_from' => 'required|date',
            'period1_to'   => 'required|date|after_or_equal:period1_from',
            'period2_from' => 'required|date',
            'period2_to'   => 'required|date|after_or_equal:period2_from',
            'subject_id'   => 'nullable|exists:subjects,id',
        ]);

        $subjectId = $data['subject_id'] ?? null;

        $period1 = $this->buildPeriodReport(
            $student,
            Carbon::parse($data['period1_from'])->startOfDay(),
            Carbon::parse($data['period1_to'])->endOfDay(),
            $subjectId
        );
        $period2 = $this->buildPeriodReport(
            $student,
            Carbon::parse($data['period2_from'])->startOfDay(),
            Carbon::parse($data['period2_to'])->endOfDay(),
            $subjectId
        );

        return response()->json([
            'message' => __('Student progress'),
            'data'    => [
                'student'    => ['id' => $student->id, 'name' => $student->user?->name],
                'subject_id' => $subjectId,
                'period_1'   => $period1,
                'period_2'   => $period2,
                'comparison' => $this->buildComparison($period1, $period2),
            ],
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 5️⃣  GET /api/parent/children-progress/{student}
    //     الأب يرى تقدّم ابن واحد بالتفصيل
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    public function oneChildProgress(Request $request, Student $student): JsonResponse
    {
        if ($request->user()->role !== 'parent') {
            return response()->json(['error' => __('This endpoint is for parents only.')], 403);
        }

        $parent = $request->user()->parent;

        if (! $parent || $parent->status !== 'approved') {
            return response()->json(['error' => __('Your account has not been approved by the admin yet.')], 403);
        }

        // التأكد أن الطالب ابن هذا الأب فعلاً
        if (! $parent->students()->where('id', $student->id)->exists()) {
            return response()->json(['error' => __('This student is not one of your children.')], 403);
        }

        $data = $request->validate([
            'period1_from' => 'required|date',
            'period1_to'   => 'required|date|after_or_equal:period1_from',
            'period2_from' => 'required|date',
            'period2_to'   => 'required|date|after_or_equal:period2_from',
            'subject_id'   => 'nullable|exists:subjects,id',
        ]);

        $subjectId = $data['subject_id'] ?? null;

        $period1 = $this->buildPeriodReport(
            $student,
            Carbon::parse($data['period1_from'])->startOfDay(),
            Carbon::parse($data['period1_to'])->endOfDay(),
            $subjectId
        );
        $period2 = $this->buildPeriodReport(
            $student,
            Carbon::parse($data['period2_from'])->startOfDay(),
            Carbon::parse($data['period2_to'])->endOfDay(),
            $subjectId
        );

        return response()->json([
            'message' => __('Your child progress'),
            'data'    => [
                'student'    => ['id' => $student->id, 'name' => $student->user?->name],
                'subject_id' => $subjectId,
                'period_1'   => $period1,
                'period_2'   => $period2,
                'comparison' => $this->buildComparison($period1, $period2),
            ],
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Core helpers (موجودة من قبل — لم تتغير)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    protected function buildPeriodReport(Student $student, Carbon $from, Carbon $to, ?int $subjectId): array
    {
        return [
            'from'       => $from->toDateString(),
            'to'         => $to->toDateString(),
            'grades'     => $this->gradesSummary($student, $from, $to, $subjectId),
            'attendance' => $this->attendanceSummary($student, $from, $to),
            'homework'   => $this->homeworkSummary($student, $from, $to, $subjectId),
        ];
    }

    protected function gradesSummary(Student $student, Carbon $from, Carbon $to, ?int $subjectId): array
    {
        $query = Grade::query()
            ->where('student_id', $student->id)
            ->whereHas('exam', function ($q) use ($from, $to, $subjectId) {
                $q->whereBetween('start_time', [$from, $to]);
                if ($subjectId) $q->where('subject_id', $subjectId);
            });

        $marks = $query->pluck('mark');

        return [
            'exams_count'  => $marks->count(),
            'average_mark' => $marks->isNotEmpty() ? round($marks->avg(), 2) : null,
            'highest_mark' => $marks->isNotEmpty() ? $marks->max() : null,
            'lowest_mark'  => $marks->isNotEmpty() ? $marks->min() : null,
        ];
    }

    protected function attendanceSummary(Student $student, Carbon $from, Carbon $to): array
    {
        $presentDays = Attendance::query()
            ->where('student_id', $student->id)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->count();

        $totalCalendarDays = $from->diffInDays($to) + 1;
        $holidayDays       = Holiday::countOverlappingDays($from, $to);
        $schoolDays        = max($totalCalendarDays - $holidayDays, 0);

        return [
            'present_days'        => $presentDays,
            'total_calendar_days' => $totalCalendarDays,
            'holiday_days'        => $holidayDays,
            'school_days'         => $schoolDays,
            'attendance_rate'     => $schoolDays > 0
                ? round(($presentDays / $schoolDays) * 100, 2)
                : null,
        ];
    }

    protected function homeworkSummary(Student $student, Carbon $from, Carbon $to, ?int $subjectId): array
    {
        $submissions = HomeworkSubmission::query()
            ->where('student_id', $student->id)
            ->whereHas('homework', function ($q) use ($from, $to, $subjectId) {
                $q->whereDate('due_date', '>=', $from->toDateString())
                  ->whereDate('due_date', '<=', $to->toDateString());
                if ($subjectId) $q->where('subject_id', $subjectId);
            })
            ->get();

        $totalDue       = $submissions->count();
        $submittedCount = $submissions->whereIn('status', ['submitted', 'late', 'graded'])->count();
        $gradedMarks    = $submissions->where('status', 'graded')->pluck('mark')->filter();

        return [
            'total_due'       => $totalDue,
            'submitted_count' => $submittedCount,
            'completion_rate' => $totalDue > 0
                ? round(($submittedCount / $totalDue) * 100, 2)
                : null,
            'average_mark'    => $gradedMarks->isNotEmpty()
                ? round($gradedMarks->avg(), 2)
                : null,
        ];
    }

    protected function buildComparison(array $period1, array $period2): array
    {
        return [
            'grades_average'          => $this->diff($period1['grades']['average_mark'],    $period2['grades']['average_mark']),
            'attendance_rate'         => $this->diff($period1['attendance']['attendance_rate'], $period2['attendance']['attendance_rate']),
            'homework_completion_rate'=> $this->diff($period1['homework']['completion_rate'],  $period2['homework']['completion_rate']),
            'homework_average_mark'   => $this->diff($period1['homework']['average_mark'],     $period2['homework']['average_mark']),
        ];
    }

    protected function diff($value1, $value2): array
    {
        if ($value1 === null || $value2 === null) {
            return ['period_1' => $value1, 'period_2' => $value2, 'difference' => null, 'trend' => 'not_enough_data'];
        }

        $difference = round($value2 - $value1, 2);

        return [
            'period_1'   => $value1,
            'period_2'   => $value2,
            'difference' => $difference,
            'trend'      => match (true) {
                $difference > 0 => 'improved',
                $difference < 0 => 'declined',
                default         => 'stable',
            },
        ];
    }
}
