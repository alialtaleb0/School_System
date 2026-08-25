<?php

// ══════════════════════════════════════════════════════════════
// ملف: app/Http/Controllers/AbsenceJustificationController.php
// طلبات تبرير الغياب (Absence Justifications) - بإذن الله تعالى
//
// الفكرة: الطالب (أو وليّ أمره نيابةً عنه) يقدّم طلب تبرير لغياب حصل
// في تاريخ معيّن، مرفقاً بسبب ومستند اختياري (تقرير طبي مثلاً). يصل
// الطلب لكل معلمي هذا الطالب الفعليين (حسب جدوله) وللأدمن، ويستطيع
// أيّ منهم قبوله أو رفضه مع ملاحظة مراجعة.
// ══════════════════════════════════════════════════════════════

namespace App\Http\Controllers;

use App\Models\AbsenceJustification;
use App\Models\ParentModel;
use App\Models\Student;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AbsenceJustificationController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * تحديد الطالب المستهدف بالطلب حسب دور المستخدم الحالي:
     * - طالب: نفسه فقط.
     * - وليّ أمر: أحد أبنائه المرتبطين فعلاً بحسابه (student_id مطلوب في الطلب).
     * يُعيد null إن تعذّر تحديد طالب صالح.
     */
    private function resolveTargetStudent(Request $request): ?Student
    {
        $user = auth()->user();

        if ($user->role === 'student') {
            return $user->student;
        }

        if ($user->role === 'parent') {
            $parent = $user->parent;

            if (! $parent || $parent->status !== 'approved') {
                return null;
            }

            $studentId = $request->input('student_id');

            if (! $studentId) {
                return null;
            }

            return $parent->students()->where('id', $studentId)->first();
        }

        return null;
    }

    /**
     * هل يحق للمستخدم الحالي (معلم/أدمن) مراجعة طلب هذا الطالب؟
     */
    private function canReview(Student $student): bool
    {
        $user = auth()->user();

        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'teacher' && $user->teacher) {
            return $user->teacher->teaches($student);
        }

        return false;
    }

    /**
     * 📌 تقديم طلب تبرير غياب جديد (الطالب نفسه أو وليّ أمره)
     * POST /api/absence-justifications
     */
    public function store(Request $request)
    {
        if (! in_array(auth()->user()->role, ['student', 'parent'])) {
            return response()->json([
                'error' => __('Only students or their parents can submit absence justification requests')
            ], 403);
        }

        $student = $this->resolveTargetStudent($request);

        if (! $student) {
            return response()->json([
                'error' => __('Unable to determine a valid student for this request')
            ], 403);
        }

        $data = $request->validate([
            'absence_date' => 'required|date|before_or_equal:today',
            'reason' => 'required|string|max:1000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $duplicate = AbsenceJustification::where('student_id', $student->id)
            ->whereDate('absence_date', $data['absence_date'])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'error' => __('A justification request already exists for this student on this date')
            ], 422);
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('absence-justifications/' . $student->id, 'public');
        }

        $justification = AbsenceJustification::create([
            'student_id' => $student->id,
            'submitted_by' => auth()->id(),
            'absence_date' => $data['absence_date'],
            'reason' => $data['reason'],
            'attachment_path' => $attachmentPath,
            'status' => 'pending',
        ]);

        $this->notifications->absenceJustificationRequested($justification);

        return response()->json([
            'message' => __('Absence justification request submitted successfully'),
            'data' => $justification->load('student.user'),
        ], 201);
    }

    /**
     * 📌 عرض طلباتي الخاصة (الطالب نفسه أو وليّ أمره لكل أبنائه)
     * GET /api/my-absence-justifications
     */
    public function myRequests(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
        ]);

        if ($user->role === 'student') {
            if (! $user->student) {
                return response()->json(['error' => __('No student profile linked to your account.')], 403);
            }

            $query = AbsenceJustification::where('student_id', $user->student->id);
        } elseif ($user->role === 'parent') {
            $parent = $user->parent;

            if (! $parent || $parent->status !== 'approved') {
                return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
            }

            $query = AbsenceJustification::whereIn('student_id', $parent->students()->pluck('id'));
        } else {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $requests = $query->with(['student.user', 'reviewer'])->latest('absence_date')->get();

        return response()->json([
            'message' => __('Absence justification requests retrieved successfully'),
            'data' => $requests,
        ]);
    }

    /**
     * 📌 عرض الطلبات الواردة (المعلم يرى طلاب جدوله فقط، الأدمن يرى الجميع)
     * GET /api/absence-justifications
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        if (! in_array($user->role, ['admin', 'teacher'])) {
            return response()->json([
                'error' => __('Only admins and teachers can view absence justification requests')
            ], 403);
        }

        $data = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'student_id' => 'nullable|exists:students,id',
            'date' => 'nullable|date',
        ]);

        $query = AbsenceJustification::with(['student.user', 'submitter', 'reviewer']);

        if ($user->role === 'teacher') {
            if (! $user->teacher) {
                return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
            }

            $studentIds = $user->teacher->scheduledStudents()->pluck('id');
            $query->whereIn('student_id', $studentIds);
        }

        if (! empty($data['student_id'])) {
            $query->where('student_id', $data['student_id']);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['date'])) {
            $query->whereDate('absence_date', $data['date']);
        }

        $requests = $query->latest('absence_date')->get();

        return response()->json([
            'message' => __('Absence justification requests retrieved successfully'),
            'data' => $requests,
        ]);
    }

    /**
     * 📌 عرض تفاصيل طلب واحد
     * GET /api/absence-justifications/{id}
     */
    public function show($id)
    {
        $justification = AbsenceJustification::with(['student.user', 'submitter', 'reviewer'])->findOrFail($id);
        $user = auth()->user();

        $isOwner = ($user->role === 'student' && $user->student && $user->student->id === $justification->student_id)
            || ($user->role === 'parent' && $user->parent && $justification->student->parent_id === $user->parent->id);

        if (! $isOwner && ! $this->canReview($justification->student)) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json([
            'message' => __('Absence justification request retrieved successfully'),
            'data' => $justification,
        ]);
    }

    /**
     * 📌 قبول طلب تبرير غياب
     * PUT/PATCH /api/absence-justifications/{id}/approve
     */
    public function approve(Request $request, $id)
    {
        return $this->review($request, $id, 'approved');
    }

    /**
     * 📌 رفض طلب تبرير غياب
     * PUT/PATCH /api/absence-justifications/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        return $this->review($request, $id, 'rejected');
    }

    /**
     * منطق مشترك لقبول/رفض الطلب، لتفادي تكرار نفس التحقّقات مرتين.
     */
    private function review(Request $request, $id, string $status)
    {
        if (! in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json([
                'error' => __('Only admins and teachers can review absence justification requests')
            ], 403);
        }

        $justification = AbsenceJustification::with('student')->findOrFail($id);

        if (! $this->canReview($justification->student)) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        if (! $justification->isPending()) {
            return response()->json([
                'error' => __('Only pending requests can be reviewed. Current status: :status', ['status' => $justification->status])
            ], 422);
        }

        $rules = ['review_note' => 'nullable|string|max:1000'];
        if ($status === 'rejected') {
            $rules['review_note'] = 'required|string|max:1000';
        }
        $data = $request->validate($rules);

        $justification->update([
            'status' => $status,
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->notifications->absenceJustificationReviewed($justification);

        return response()->json([
            'message' => $status === 'approved'
                ? __('Absence justification request approved successfully')
                : __('Absence justification request rejected successfully'),
            'data' => $justification->fresh(['student.user', 'reviewer']),
        ]);
    }

    /**
     * 📌 حذف طلب (صاحبه فقط طالما لا يزال pending، أو الأدمن في أي وقت)
     * DELETE /api/absence-justifications/{id}
     */
    public function destroy($id)
    {
        $justification = AbsenceJustification::with('student')->findOrFail($id);
        $user = auth()->user();

        $isOwner = ($user->role === 'student' && $user->student && $user->student->id === $justification->student_id)
            || ($user->role === 'parent' && $user->parent && $justification->student->parent_id === $user->parent->id);

        if ($user->role !== 'admin' && ! ($isOwner && $justification->isPending())) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        if ($justification->attachment_path) {
            Storage::disk('public')->delete($justification->attachment_path);
        }

        $justification->delete();

        return response()->json([
            'message' => __('Absence justification request deleted successfully')
        ]);
    }
}
