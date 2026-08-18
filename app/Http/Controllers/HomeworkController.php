<?php

namespace App\Http\Controllers;

use App\Models\Homework;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class HomeworkController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    public function index()
    {
        $user = auth()->user();

        if ($user->role === 'admin') {
            $homeworks = Homework::with(['subject', 'teacher.user', 'level', 'section'])->get();
        } elseif ($user->role === 'teacher') {
            $homeworks = Homework::with(['subject', 'teacher.user', 'level', 'section'])
                ->where('teacher_id', $user->teacher->id)
                ->get();
        } else {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json([
            'message' => __('Homeworks retrieved successfully'),
            'data' => $homeworks
        ]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'teacher') {
            return response()->json(['error' => __('Only teachers can create homeworks')], 403);
        }

        $data = $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'level_id' => 'required|exists:levels,id',
            'section_id' => 'required|exists:sections,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $homework = Homework::create([
            'subject_id' => $data['subject_id'],
            'teacher_id' => $user->teacher->id,
            'level_id' => $data['level_id'],
            'section_id' => $data['section_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ]);

        // إشعار كل طلاب الشعبة/المستوى المعنيّين بالواجب الجديد
        $students = StudentEnrollment::where('status', 'approved')
            ->where('level_id', $homework->level_id)
            ->where('section_id', $homework->section_id)
            ->with('student.user')
            ->get()
            ->pluck('student')
            ->filter();

        $this->notifications->newHomework($homework->load('subject'), $students);

        return response()->json([
            'message' => __('Homework created successfully'),
            'data' => $homework->load(['subject', 'teacher.user', 'level', 'section'])
        ], 201);
    }

    public function show(Homework $homework)
    {
        $user = auth()->user();

        if ($user->role === 'student') {
            $student = $user->student;
            if (!$student || $student->level_id !== $homework->level_id || $student->section_id !== $homework->section_id) {
                return response()->json(['error' => __('Unauthorized')], 403);
            }
        } elseif ($user->role === 'teacher' && $user->teacher->id !== $homework->teacher_id) {
            return response()->json(['error' => __('Unauthorized')], 403);
        } elseif ($user->role !== 'admin' && $user->role !== 'teacher' && $user->role !== 'student') {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json([
            'message' => __('Homework retrieved successfully'),
            'data' => $homework->load(['subject', 'teacher.user', 'level', 'section'])
        ]);
    }

    public function update(Request $request, Homework $homework)
    {
        $user = auth()->user();

        if ($user->role !== 'teacher' || $user->teacher->id !== $homework->teacher_id) {
            return response()->json(['error' => __('Only the owner teacher can update this homework')], 403);
        }

        $data = $request->validate([
            'subject_id' => 'nullable|exists:subjects,id',
            'level_id' => 'nullable|exists:levels,id',
            'section_id' => 'nullable|exists:sections,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $homework->update(array_filter([
            'subject_id' => $data['subject_id'] ?? null,
            'level_id' => $data['level_id'] ?? null,
            'section_id' => $data['section_id'] ?? null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ], function ($value) {
            return $value !== null;
        }));

        return response()->json([
            'message' => __('Homework updated successfully'),
            'data' => $homework->load(['subject', 'teacher.user', 'level', 'section'])
        ]);
    }

    public function destroy(Homework $homework)
    {
        $user = auth()->user();

        if ($user->role !== 'teacher' || $user->teacher->id !== $homework->teacher_id) {
            return response()->json(['error' => __('Only the owner teacher can delete this homework')], 403);
        }

        $homework->delete();

        return response()->json([
            'message' => __('Homework deleted successfully')
        ]);
    }

    public function myHomeworks()
    {
        $user = auth()->user();

        if ($user->role !== 'student') {
            return response()->json(['error' => __('Only students can view their homeworks')], 403);
        }

        $student = $user->student;
        if (!$student) {
            return response()->json(['error' => __('Student profile not found')], 404);
        }

        $homeworks = Homework::with(['subject', 'teacher.user', 'level', 'section'])
            ->where('level_id', $student->enrollments()->latest()->first()->level_id)
            ->where('section_id', $student->enrollments()->latest()->first()->section_id)
            ->get();

        return response()->json([
            'message' => __('Student homeworks retrieved successfully'),
            'data' => $homeworks
        ]);
    }
}
