<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * عرض جميع سجلات الحضور (للمعلم أو الأدمن)
     */
    public function index(Request $request)
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json([
                'error' => __('Only admins and teachers can view attendance records')
            ], 403);
        }

        $data = $request->validate([
            'student_id' => 'nullable|exists:students,id',
            'date' => 'nullable|date'
        ]);

        $query = Attendance::with('student.user');

        if (!empty($data['student_id'])) {
            $query->where('student_id', $data['student_id']);
        }

        if (!empty($data['date'])) {
            $query->whereDate('date', $data['date']);
        }

        $attendances = $query->latest('date')->get();

        return response()->json([
            'message' => __('Attendance records retrieved successfully'),
            'data' => $attendances
        ]);
    }

    /**
     * تسجيل حضور طالب
     */
    public function store(Request $request)
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json([
                'error' => __('Only admins and teachers can record attendance')
            ], 403);
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'date' => 'nullable|date'
        ]);

        $date = $data['date'] ?? now()->toDateString();

        $existing = Attendance::where('student_id', $data['student_id'])
            ->whereDate('date', $date)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => __('Attendance already recorded for this student on this date')
            ], 422);
        }

        $attendance = Attendance::create([
            'student_id' => $data['student_id'],
            'date' => $date,
        ]);

        $this->notifications->attendanceRecorded($attendance);

        return response()->json([
            'message' => __('Attendance recorded successfully'),
            'data' => $attendance
        ], 201);
    }

    /**
     * تسجيل حضور طالب عبر المسار student/{id}/attendance
     */
    public function storeForStudent(Request $request, $id)
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json([
                'error' => __('Only admins and teachers can record attendance')
            ], 403);
        }

        $student = Student::findOrFail($id);

        $data = $request->validate([
            'date' => 'nullable|date'
        ]);

        $date = $data['date'] ?? now()->toDateString();

        $existing = $student->attendance()
            ->whereDate('date', $date)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => __('Attendance already recorded for this student on this date')
            ], 422);
        }

        $attendance = $student->attendance()->create([
            'date' => $date,
        ]);

        $this->notifications->attendanceRecorded($attendance);

        return response()->json([
            'message' => __('Attendance recorded successfully'),
            'data' => $attendance
        ], 201);
    }

    /**
     * عرض سجل الحضور لطالب محدد
     */
    public function studentAttendance(Request $request, $id)
    {
        $student = Student::findOrFail($id);

        if (auth()->user()->role === 'student' && auth()->user()->student->id !== $student->id) {
            return response()->json([
                'error' => __('Unauthorized')
            ], 403);
        }

        $data = $request->validate([
            'date' => 'nullable|date'
        ]);

        $query = $student->attendance();

        if (!empty($data['date'])) {
            $query->whereDate('date', $data['date']);
        }

        $attendance = $query->latest('date')->get();

        return response()->json([
            'message' => __('Student attendance retrieved successfully'),
            'data' => $attendance
        ]);
    }

    /**
     * عرض حضور الطالب اليوم
     */
    public function studentAttendanceToday($id)
    {
        $student = Student::findOrFail($id);

        if (auth()->user()->role === 'student' && auth()->user()->student->id !== $student->id) {
            return response()->json([
                'error' => __('Unauthorized')
            ], 403);
        }

        $attendance = $student->attendance()
            ->whereDate('date', now()->toDateString())
            ->get();

        return response()->json([
            'message' => __('Student today attendance retrieved successfully'),
            'data' => $attendance
        ]);
    }

    /**
     * عرض حضور اليوم
     */
    public function today()
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json([
                'error' => __('Only admins and teachers can view today attendance')
            ], 403);
        }

        $attendances = Attendance::with('student.user')
            ->whereDate('date', now()->toDateString())
            ->latest('student_id')
            ->get();

        return response()->json([
            'message' => __('Today attendance retrieved successfully'),
            'data' => $attendances
        ]);
    }

    /**
     * عرض سجل حضور واحد
     */
    public function show($id)
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json([
                'error' => __('Only admins and teachers can view attendance records')
            ], 403);
        }

        $attendance = Attendance::with('student.user')->findOrFail($id);

        return response()->json([
            'message' => __('Attendance record retrieved successfully'),
            'data' => $attendance
        ]);
    }

    /**
     * حذف سجل حضور خاطئ
     */
    public function destroy($id)
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json([
                'error' => __('Only admins and teachers can delete attendance records')
            ], 403);
        }

        $attendance = Attendance::findOrFail($id);
        $attendance->delete();

        return response()->json([
            'message' => __('Attendance record deleted successfully')
        ]);
    }
}
