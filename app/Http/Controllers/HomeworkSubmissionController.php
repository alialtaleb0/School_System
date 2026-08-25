<?php

namespace App\Http\Controllers;

use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\HomeworkSubmissionAttachment;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HomeworkSubmissionController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * عرض كل التسليمات لواجب معيّن (للمعلم/الأدمن)
     * GET /api/homeworks/{homework}/submissions
     */
    public function index(Homework $homework)
    {
        if (! in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $submissions = $homework->submissions()->with(['student.user', 'attachments'])->get();

        return response()->json(['data' => $submissions]);
    }

    /**
     * تسجيل تسليم الطالب لواجب مع إمكانية رفع عدة ملفات (الطالب نفسه يسلّم).
     * POST /api/homeworks/{homework}/submit
     * body (multipart/form-data): { "attachments[]": [file, file, ...] }
     */
    public function submit(Request $request, Homework $homework)
    {
        if (auth()->user()->role !== 'student') {
            return response()->json(['error' => __('Only students can submit homework.')], 403);
        }

        $student = auth()->user()->student;

        if (!$student) {
            return response()->json(['error' => __('Student profile not found')], 404);
        }

        $existing = HomeworkSubmission::where('homework_id', $homework->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existing && $existing->status !== 'pending') {
            return response()->json(['error' => __('Homework already submitted.')], 422);
        }

        $data = $request->validate([
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,txt',
        ]);

        if (empty($request->file('attachments'))) {
            return response()->json(['error' => __('You must upload at least one file to submit.')], 422);
        }

        $isLate = $homework->due_date && now()->toDateString() > $homework->due_date;

        $submission = DB::transaction(function () use ($request, $homework, $student, $existing, $isLate) {
            $submission = $existing ?: new HomeworkSubmission([
                'homework_id' => $homework->id,
                'student_id' => $student->id,
            ]);

            $submission->fill([
                'status' => $isLate ? 'late' : 'submitted',
                'submitted_at' => now(),
            ])->save();

            foreach ($request->file('attachments') as $file) {
                $path = $file->store('homework-submissions/' . $submission->id, 'public');
                $isImage = Str::startsWith($file->getMimeType(), 'image/');

                HomeworkSubmissionAttachment::create([
                    'homework_submission_id' => $submission->id,
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => $isImage ? 'image' : 'file',
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            return $submission;
        });

        $submission->load('attachments');

        return response()->json([
            'message' => __('Homework submitted successfully'),
            'data' => $submission,
        ], 201);
    }

    /**
     * تقييم تسليم طالب (المعلم يضع علامة وملاحظة).
     * PUT/PATCH /api/homework-submissions/{submission}/grade
     */
    public function grade(Request $request, HomeworkSubmission $submission)
    {
        if (auth()->user()->role !== 'teacher') {
            return response()->json(['error' => __('Only teachers can grade homework.')], 403);
        }

        $teacher = auth()->user()->teacher;
        $homeworkTeacherId = $submission->homework->teacher_id;

        if ($teacher->id !== $homeworkTeacherId) {
            return response()->json(['error' => __('You cannot grade a homework you do not teach.')], 403);
        }

        $data = $request->validate([
            'mark' => 'required|numeric|min:0',
            'feedback' => 'nullable|string',
        ]);

        $submission->update([
            'mark' => $data['mark'],
            'feedback' => $data['feedback'] ?? null,
            'status' => 'graded',
        ]);

        $this->notifications->homeworkGraded($submission);

        return response()->json([
            'message' => __('Homework graded successfully'),
            'data' => $submission,
        ]);
    }
}
