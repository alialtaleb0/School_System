<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\TeacherAttendance;
use Illuminate\Http\Request;

class TeacherAttendanceController extends Controller
{
    /**
     * عرض جميع سجلات حضور المعلمين (للأدمن فقط)
     * GET /api/teacher-attendance
     */
    public function index(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can view teacher attendance records')
            ], 403);
        }

        $data = $request->validate([
            'teacher_id' => 'nullable|exists:teachers,id',
            'date' => 'nullable|date',
            'status' => 'nullable|in:present,absent,late,excused',
        ]);

        $query = TeacherAttendance::with('teacher.user');

        if (!empty($data['teacher_id'])) {
            $query->where('teacher_id', $data['teacher_id']);
        }

        if (!empty($data['date'])) {
            $query->whereDate('date', $data['date']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $attendances = $query->latest('date')->get();

        return response()->json([
            'message' => __('Teacher attendance records retrieved successfully'),
            'data' => $attendances,
        ]);
    }

    /**
     * تسجيل حضور/غياب معلم (الأدمن فقط)
     * POST /api/teacher-attendance
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can record teacher attendance')
            ], 403);
        }

        $data = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
            'date' => 'nullable|date',
            'status' => 'nullable|in:present,absent,late,excused',
            'notes' => 'nullable|string',
        ]);

        $date = $data['date'] ?? now()->toDateString();

        $existing = TeacherAttendance::where('teacher_id', $data['teacher_id'])
            ->whereDate('date', $date)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => __('Attendance already recorded for this teacher on this date')
            ], 422);
        }

        $attendance = TeacherAttendance::create([
            'teacher_id' => $data['teacher_id'],
            'date' => $date,
            'status' => $data['status'] ?? 'present',
            'notes' => $data['notes'] ?? null,
            'recorded_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => __('Teacher attendance recorded successfully'),
            'data' => $attendance,
        ], 201);
    }

    /**
     * تسجيل حضور معلم عبر المسار teacher-attendance/{id} (الأدمن فقط)
     * POST /api/teacher-attendance/{id}
     */
    public function storeForTeacher(Request $request, $id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can record teacher attendance')
            ], 403);
        }

        $teacher = Teacher::findOrFail($id);

        $data = $request->validate([
            'date' => 'nullable|date',
            'status' => 'nullable|in:present,absent,late,excused',
            'notes' => 'nullable|string',
        ]);

        $date = $data['date'] ?? now()->toDateString();

        $existing = $teacher->attendances()
            ->whereDate('date', $date)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => __('Attendance already recorded for this teacher on this date')
            ], 422);
        }

        $attendance = $teacher->attendances()->create([
            'date' => $date,
            'status' => $data['status'] ?? 'present',
            'notes' => $data['notes'] ?? null,
            'recorded_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => __('Teacher attendance recorded successfully'),
            'data' => $attendance,
        ], 201);
    }

    /**
     * تعديل سجل حضور معلم (تصحيح الحالة أو الملاحظات) - الأدمن فقط
     * PUT/PATCH /api/teacher-attendance/{id}
     */
    public function update(Request $request, $id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can update teacher attendance records')
            ], 403);
        }

        $attendance = TeacherAttendance::findOrFail($id);

        $data = $request->validate([
            'status' => 'sometimes|required|in:present,absent,late,excused',
            'notes' => 'nullable|string',
        ]);

        $attendance->update($data);

        return response()->json([
            'message' => __('Teacher attendance record updated successfully'),
            'data' => $attendance,
        ]);
    }

    /**
     * عرض سجل حضوري أنا (المعلم المسجّل دخوله حالياً) - بدون الحاجة لتمرير أي id
     * الهوية تُستنتج بالكامل من التوكن (auth()->user()->teacher)
     * GET /api/teacher-attendance/me
     */
    public function myAttendance(Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'teacher' || !$user->teacher) {
            return response()->json(['error' => __('Only teachers have attendance records')], 403);
        }

        $data = $request->validate([
            'date' => 'nullable|date',
        ]);

        $query = $user->teacher->attendances();

        if (!empty($data['date'])) {
            $query->whereDate('date', $data['date']);
        }

        $attendance = $query->latest('date')->get();

        return response()->json([
            'message' => __('My attendance retrieved successfully'),
            'data' => $attendance,
        ]);
    }

    /**
     * عرض حضوري أنا اليوم - بدون الحاجة لتمرير أي id
     * GET /api/teacher-attendance/me/today
     */
    public function myAttendanceToday()
    {
        $user = auth()->user();

        if ($user->role !== 'teacher' || !$user->teacher) {
            return response()->json(['error' => __('Only teachers have attendance records')], 403);
        }

        $attendance = $user->teacher->attendances()
            ->whereDate('date', now()->toDateString())
            ->get();

        return response()->json([
            'message' => __('My today attendance retrieved successfully'),
            'data' => $attendance,
        ]);
    }

    /**
     * عرض سجل حضور معلم محدد (للأدمن فقط - يمرّر id المعلم المطلوب الاطلاع عليه)
     * GET /api/teacher-attendance/{id}
     */
    public function teacherAttendance(Request $request, $id)
    {
        $user = auth()->user();

        if ($user->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view another teacher\'s attendance by id. Use /teacher-attendance/me for your own records.')], 403);
        }

        $teacher = Teacher::findOrFail($id);

        $data = $request->validate([
            'date' => 'nullable|date',
        ]);

        $query = $teacher->attendances();

        if (!empty($data['date'])) {
            $query->whereDate('date', $data['date']);
        }

        $attendance = $query->latest('date')->get();

        return response()->json([
            'message' => __('Teacher attendance retrieved successfully'),
            'data' => $attendance,
        ]);
    }

    /**
     * عرض حضور معلم محدد اليوم (للأدمن فقط)
     * GET /api/teacher-attendance/{id}/today
     */
    public function teacherAttendanceToday($id)
    {
        $user = auth()->user();

        if ($user->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view another teacher\'s attendance by id. Use /teacher-attendance/me/today for your own record.')], 403);
        }

        $teacher = Teacher::findOrFail($id);

        $attendance = $teacher->attendances()
            ->whereDate('date', now()->toDateString())
            ->get();

        return response()->json([
            'message' => __('Teacher today attendance retrieved successfully'),
            'data' => $attendance,
        ]);
    }

    /**
     * لوحة حضور اليوم لجميع المعلمين (الأدمن فقط)
     * GET /api/teacher-attendance/today
     */
    public function today()
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can view today teacher attendance')
            ], 403);
        }

        $attendances = TeacherAttendance::with('teacher.user')
            ->whereDate('date', now()->toDateString())
            ->latest('teacher_id')
            ->get();

        return response()->json([
            'message' => __('Today teacher attendance retrieved successfully'),
            'data' => $attendances,
        ]);
    }

    /**
     * حذف سجل حضور خاطئ (الأدمن فقط)
     * DELETE /api/teacher-attendance/{id}
     */
    public function destroy($id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can delete teacher attendance records')
            ], 403);
        }

        $attendance = TeacherAttendance::findOrFail($id);
        $attendance->delete();

        return response()->json([
            'message' => __('Teacher attendance record deleted successfully'),
        ]);
    }
}
