<?php

// ══════════════════════════════════════════════════════════════
// ملف: app/Http/Controllers/StudentNoteController.php
// ملاحظات المعلمين عن الطلاب والرد عليها (Student Notes & Replies) - بإذن الله تعالى
//
// الفكرة: المعلم يكتب ملاحظة عن أحد طلابه الفعليين (سلوكية/أكاديمية/عامة)،
// فتصل لهذا الطالب ووليّ أمره. يستطيع الطالب أو وليّ أمره (أو أيّ معلم آخر
// يُدرّس نفس الطالب، أو الأدمن) متابعة الملاحظة والرد عليها ضمن نفس الخيط،
// فتتكوّن محادثة مركّزة حول ملاحظة واحدة بدل الدردشة العامة.
// ══════════════════════════════════════════════════════════════

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentNote;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class StudentNoteController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * هل يحق للمستخدم الحالي الاطلاع على هذه الملاحظة (ومن ثم الرد عليها)؟
     */
    private function canAccess(StudentNote $note): bool
    {
        $user = auth()->user();

        return match (true) {
            $user->role === 'admin' => true,
            $user->role === 'teacher' => (bool) ($user->teacher && $user->teacher->teaches($note->student)),
            $user->role === 'student' => (bool) ($user->student && $user->student->id === $note->student_id),
            $user->role === 'parent' => (bool) (
                $user->parent
                && $user->parent->status === 'approved'
                && $note->student->parent_id === $user->parent->id
            ),
            default => false,
        };
    }

    /**
     * 📌 المعلم يكتب ملاحظة جديدة عن أحد طلابه الفعليين
     * POST /api/student-notes
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'teacher') {
            return response()->json(['error' => __('Only teachers can add notes about students')], 403);
        }

        $teacher = auth()->user()->teacher;

        if (! $teacher) {
            return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'title' => 'nullable|string|max:255',
            'body' => 'required|string|max:2000',
        ]);

        $student = Student::findOrFail($data['student_id']);

        if (! $teacher->teaches($student)) {
            return response()->json([
                'error' => __('This student is not among your students according to the schedule.')
            ], 403);
        }

        $note = StudentNote::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
        ]);

        $this->notifications->studentNoteCreated($note);

        return response()->json([
            'message' => __('Note added successfully'),
            'data' => $note->load(['student.user', 'teacher.user']),
        ], 201);
    }

    /**
     * 📌 عرض الملاحظات حسب الدور:
     * - المعلم: ملاحظات طلابه الفعليين (كل الملاحظات عليهم، وليس فقط ملاحظاته)
     * - وليّ الأمر: ملاحظات أبنائه
     * - الطالب: ملاحظاته الخاصة
     * - الأدمن: الجميع
     * GET /api/student-notes
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'student_id' => 'nullable|exists:students,id',
        ]);

        $query = StudentNote::with(['student.user', 'teacher.user'])->withCount('replies');

        if ($user->role === 'teacher') {
            if (! $user->teacher) {
                return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
            }

            $query->whereIn('student_id', $user->teacher->scheduledStudents()->pluck('id'));
        } elseif ($user->role === 'parent') {
            if (! $user->parent || $user->parent->status !== 'approved') {
                return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
            }

            $query->whereIn('student_id', $user->parent->students()->pluck('id'));
        } elseif ($user->role === 'student') {
            if (! $user->student) {
                return response()->json(['error' => __('No student profile linked to your account.')], 403);
            }

            $query->where('student_id', $user->student->id);
        } elseif ($user->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        if (! empty($data['student_id'])) {
            $query->where('student_id', $data['student_id']);
        }

        $notes = $query->latest()->get();

        return response()->json([
            'message' => __('Notes retrieved successfully'),
            'data' => $notes,
        ]);
    }

    /**
     * 📌 عرض ملاحظة واحدة مع كامل خيط الردود عليها
     * GET /api/student-notes/{id}
     */
    public function show($id)
    {
        $note = StudentNote::with(['student.user', 'teacher.user', 'replies.author'])->findOrFail($id);

        if (! $this->canAccess($note)) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json([
            'message' => __('Note retrieved successfully'),
            'data' => $note,
        ]);
    }

    /**
     * 📌 الرد على ملاحظة (المعلم الذي يدرّس الطالب، وليّ الأمر، الطالب نفسه، أو الأدمن)
     * POST /api/student-notes/{id}/replies
     */
    public function storeReply(Request $request, $id)
    {
        $note = StudentNote::with('student')->findOrFail($id);

        if (! $this->canAccess($note)) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $reply = $note->replies()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ]);

        $reply->setRelation('note', $note);
        $this->notifications->studentNoteReplied($reply);

        return response()->json([
            'message' => __('Reply added successfully'),
            'data' => $reply->load('author'),
        ], 201);
    }

    /**
     * 📌 حذف ملاحظة (كاتبها فقط أو الأدمن)
     * DELETE /api/student-notes/{id}
     */
    public function destroy($id)
    {
        $note = StudentNote::findOrFail($id);
        $user = auth()->user();

        $isAuthor = $user->role === 'teacher' && $user->teacher && $user->teacher->id === $note->teacher_id;

        if ($user->role !== 'admin' && ! $isAuthor) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        $note->delete();

        return response()->json(['message' => __('Note deleted successfully')]);
    }
}
