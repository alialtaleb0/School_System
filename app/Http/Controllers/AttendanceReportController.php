<?php

// ══════════════════════════════════════════════════════════════
// ملف: app/Http/Controllers/AttendanceReportController.php
// تقارير الحضور والغياب الشاملة (Attendance Reports) - بإذن الله تعالى
//
// على عكس AttendanceController الذي يعرض سجلات خام (طالب واحد/تاريخ واحد)،
// هذا الكنترولر يبني تقريراً إحصائياً مجمّعاً لفترة زمنية كاملة: نسبة حضور
// عامة، نسبة حضور لكل شعبة، ونسبة حضور لكل طالب (مرتّبة من الأسوأ للأفضل
// لتسهيل رصد الغياب المتكرر)، مع اتجاه يومي لعدد الحاضرين.
// ══════════════════════════════════════════════════════════════

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\StudentEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceReportController extends Controller
{
    /**
     * 📌 تقرير الحضور والغياب الشامل (الأدمن فقط)
     * GET /api/admin/reports/attendance
     *
     * فلاتر اختيارية: from, to (افتراضياً آخر 30 يوماً)، section_id، level_id
     */
    public function summary(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view attendance reports')], 403);
        }

        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'section_id' => 'nullable|exists:sections,id',
            'level_id' => 'nullable|exists:levels,id',
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to'])->startOfDay() : Carbon::now()->startOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29);

        // ─ أيام الدوام الفعلية ضمن الفترة (باستثناء الجمعة/السبت والعطل الرسمية) ─
        $schoolDays = collect();
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($day->isFriday() || $day->isSaturday()) {
                continue;
            }
            if (Holiday::isHoliday($day->toDateString())) {
                continue;
            }
            $schoolDays->push($day->toDateString());
        }
        $schoolDaysCount = $schoolDays->count();

        // ─ الطلاب المستهدفون حسب آخر تسجيل مقبول لكل طالب (مع فلترة اختيارية بالشعبة/الصف) ─
        $enrollmentsQuery = StudentEnrollment::where('status', 'approved')
            ->with(['student.user', 'section.level']);

        if (!empty($data['section_id'])) {
            $enrollmentsQuery->where('section_id', $data['section_id']);
        }

        if (!empty($data['level_id'])) {
            $enrollmentsQuery->where('level_id', $data['level_id']);
        }

        $enrollments = $enrollmentsQuery->orderByDesc('id')->get()->unique('student_id')->values();

        if ($enrollments->isEmpty()) {
            return response()->json([
                'message' => __('No students found for the selected filters'),
                'data' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'school_days' => $schoolDaysCount,
                    'overall' => null,
                    'by_section' => [],
                    'by_student' => [],
                    'daily' => [],
                ],
            ]);
        }

        $studentIds = $enrollments->pluck('student_id');

        // ─ سجلات الحضور الفعلية ضمن الفترة لكل هؤلاء الطلاب، بجلب واحد فقط ─
        $attendanceRows = Attendance::whereIn('student_id', $studentIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['student_id', 'date']);

        $presentCounts = $attendanceRows->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('date')->unique()->count());

        $dailyPresent = $attendanceRows->groupBy('date')->map->count();

        // ─ تفصيل حسب الطالب ─
        $byStudent = $enrollments->map(function ($enrollment) use ($presentCounts, $schoolDaysCount) {
            $present = $presentCounts->get($enrollment->student_id, 0);
            $absent = max($schoolDaysCount - $present, 0);

            return [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student->user->name ?? null,
                'section_id' => $enrollment->section_id,
                'section_name' => $enrollment->section->name ?? null,
                'level_name' => $enrollment->section->level->name ?? null,
                'present_days' => $present,
                'absent_days' => $absent,
                'attendance_rate' => $schoolDaysCount > 0 ? round($present / $schoolDaysCount * 100, 1) : null,
            ];
        })->sortBy('attendance_rate')->values();

        // ─ تفصيل حسب الشعبة ─
        $bySection = $byStudent->groupBy('section_id')->map(function ($rows) {
            $first = $rows->first();

            return [
                'section_id' => $first['section_id'],
                'section_name' => $first['section_name'],
                'level_name' => $first['level_name'],
                'students_count' => $rows->count(),
                'average_attendance_rate' => round($rows->avg('attendance_rate'), 1),
            ];
        })->values();

        // ─ اتجاه يومي لعدد الحاضرين والغائبين على مستوى كل الطلاب المستهدفين ─
        $daily = $schoolDays->map(function ($date) use ($dailyPresent, $enrollments) {
            $present = $dailyPresent->get($date, 0);

            return [
                'date' => $date,
                'present_count' => $present,
                'absent_count' => max($enrollments->count() - $present, 0),
            ];
        })->values();

        $totalPresent = $byStudent->sum('present_days');
        $totalPossible = $byStudent->count() * $schoolDaysCount;

        return response()->json([
            'message' => __('Attendance report generated successfully'),
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'school_days' => $schoolDaysCount,
                'overall' => [
                    'students_count' => $byStudent->count(),
                    'total_present_days' => $totalPresent,
                    'total_possible_days' => $totalPossible,
                    'overall_attendance_rate' => $totalPossible > 0 ? round($totalPresent / $totalPossible * 100, 1) : null,
                ],
                'by_section' => $bySection,
                'by_student' => $byStudent,
                'daily' => $daily,
            ],
        ]);
    }
}
