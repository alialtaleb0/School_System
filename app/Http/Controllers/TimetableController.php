<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Section;
use App\Models\Student;
use App\Models\Timetable;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * عرض جميع الجداول الزمنية
     */
    public function index()
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view timetables')], 403);
        }

        $timetables = Timetable::with([
            'section.level',
            'section.program',
            'schedules.subject',
            'schedules.teacher.user',
        ])->get();

        return response()->json([
            'message' => __('Timetables retrieved successfully'),
            'data'    => $timetables,
        ]);
    }

    /**
     * إنشاء جدول زمني لشعبة معيّنة
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can create timetables')], 403);
        }

        $data = $request->validate([
            'section_id' => 'required|exists:sections,id',
            'name'       => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $section = Section::with(['level', 'program'])->findOrFail($data['section_id']);

        // التحقق: الشعبة يجب أن تكون مرتبطة بمرحلة دراسية
        if (!$section->level) {
            return response()->json([
                'error' => __('The selected section has no associated level (grade).'),
            ], 422);
        }

        $timetable = Timetable::create([
            'section_id' => $section->id,
            'name'       => $data['name'],
            'start_date' => $data['start_date'] ?? null,
            'end_date'   => $data['end_date'] ?? null,
        ]);

        return response()->json([
            'message' => __('Timetable created successfully'),
            'data'    => $timetable->load([
                'section.level',
                'section.program',
            ]),
        ], 201);
    }

    /**
     * عرض جدول زمني محدد
     */
    public function show(Timetable $timetable)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view timetables')], 403);
        }

        return response()->json([
            'message' => __('Timetable retrieved successfully'),
            'data'    => $timetable->load([
                'section.level',
                'section.program',
                'schedules.subject',
                'schedules.teacher.user',
            ]),
        ]);
    }

    /**
     * تعديل جدول زمني
     */
    public function update(Request $request, Timetable $timetable)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can update timetables')], 403);
        }

        $data = $request->validate([
            'name'       => 'nullable|string|max:255',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $timetable->update(array_filter($data, fn($v) => !is_null($v)));

        $this->notifications->timetableChanged(
            $timetable->section,
            null,
            __('Your section timetable data has been updated.'),
            ['timetable_id' => $timetable->id],
        );

        return response()->json([
            'message' => __('Timetable updated successfully'),
            'data'    => $timetable->load([
                'section.level',
                'section.program',
                'schedules.subject',
                'schedules.teacher.user',
            ]),
        ]);
    }

    /**
     * حذف جدول زمني
     */
    public function destroy(Timetable $timetable)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can delete timetables')], 403);
        }

        $timetable->delete();

        return response()->json([
            'message' => __('Timetable deleted successfully'),
        ]);
    }

    /**
     * عرض الحصص الخاصة بجدول زمني معيّن
     */
    public function schedules($id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view timetable schedules')], 403);
        }

        $timetable = Timetable::with(['section.level', 'section.program'])->findOrFail($id);

        return response()->json([
            'message' => __('Timetable schedules retrieved successfully'),
            'data'    => [
                'timetable' => $timetable,
                'schedules' => $timetable->schedules()->with(['subject', 'teacher.user'])->get(),
            ],
        ]);
    }

    /**
     * إضافة حصة لجدول زمني
     */
    public function addSchedule(Request $request, $id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can add schedule entries')], 403);
        }

        $timetable = Timetable::findOrFail($id);

        $data = $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'required|exists:teachers,id',
            'day'        => 'required|string|in:sunday,monday,tuesday,wednesday,thursday',
            'period'     => 'required|integer|min:1|max:7',
        ]);

        // التحقق من أن المعلم يدرّس هذه المادة
        $teacher = \App\Models\Teacher::findOrFail($data['teacher_id']);
        $doesTeachSubject = $teacher->subjects()->where('subject_id', $data['subject_id'])->exists();

        if (!$doesTeachSubject) {
            return response()->json([
                'error' => __('This teacher is not assigned to teach this subject.'),
            ], 422);
        }

        // التحقق من عدم تكرار الحصة في نفس اليوم والفترة
        $existing = Schedule::where('timetable_id', $timetable->id)
            ->where('day', $data['day'])
            ->where('period', $data['period'])
            ->first();

        if ($existing) {
            return response()->json([
                'error' => __('This period already has a scheduled subject on that day.'),
            ], 422);
        }

        $schedule = $timetable->schedules()->create($data);

        $this->notifications->timetableChanged(
            $timetable->section,
            $teacher,
            __('A new period has been added to the timetable.'),
            ['timetable_id' => $timetable->id, 'schedule_id' => $schedule->id],
        );

        return response()->json([
            'message' => __('Schedule entry added successfully'),
            'data'    => $schedule->load(['subject', 'teacher.user']),
        ], 201);
    }

    /**
     * الطالب يرى جدوله الدراسي (الجدول الزمني لشعبة他已经 enrolled فيه)
     */
    public function myTimetable()
    {
        $user = auth()->user();
        $student = $user->student;

        if (!$student) {
            return response()->json(['message' => __('Student profile not found.')], 404);
        }

        return response()->json($this->studentTimetablePayload($student));
    }

    /**
     * وليّ الأمر يرى البرنامج الدراسي لأحد أبنائه المرتبطين فعلاً بحسابه
     * GET /api/parent/timetable/{student}
     */
    public function childTimetable(Request $request, Student $student)
    {
        if ($request->user()->role !== 'parent') {
            return response()->json(['error' => __('This endpoint is for parents only.')], 403);
        }

        $parent = $request->user()->parent;

        if (!$parent || $parent->status !== 'approved') {
            return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
        }

        if (!$parent->students()->where('id', $student->id)->exists()) {
            return response()->json(['error' => __('This student is not one of your children.')], 403);
        }

        return response()->json($this->studentTimetablePayload($student));
    }

    /**
     * وليّ الأمر يرى البرنامج الدراسي لكل أبنائه دفعة واحدة
     * GET /api/parent/timetable
     */
    public function childrenTimetables(Request $request)
    {
        if ($request->user()->role !== 'parent') {
            return response()->json(['error' => __('This endpoint is for parents only.')], 403);
        }

        $parent = $request->user()->parent;

        if (!$parent || $parent->status !== 'approved') {
            return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
        }

        $children = $parent->students()->with('user')->get();

        $result = $children->map(function (Student $student) {
            $payload = $this->studentTimetablePayload($student);

            return [
                'student_id' => $student->id,
                'student_name' => $student->user->name ?? null,
                'message' => $payload['message'],
                'timetable' => $payload['data'],
            ];
        })->values();

        return response()->json([
            'message' => __('Children timetables retrieved successfully'),
            'data' => $result,
        ]);
    }

    /**
     * منطق مشترك: جدول الشعبة الحالية لطالب معيّن حسب آخر تسجيل مقبول له.
     * تُستخدم من قِبل الطالب نفسه (myTimetable) ووليّ أمره (childTimetable/childrenTimetables).
     */
    private function studentTimetablePayload(Student $student): array
    {
        $enrollment = $student->enrollments()
            ->where('status', 'approved')
            ->latest()
            ->first();

        if (!$enrollment || !$enrollment->section_id) {
            return [
                'message' => __('This student is not enrolled in any section yet.'),
                'data' => null,
            ];
        }

        $timetable = Timetable::where('section_id', $enrollment->section_id)
            ->with([
                'section.level',
                'section.program',
                'schedules.subject',
                'schedules.teacher.user',
            ])
            ->latest()
            ->first();

        if (!$timetable) {
            return [
                'message' => __('No timetable found for this section.'),
                'data' => null,
            ];
        }

        return [
            'message' => __('Timetable retrieved successfully'),
            'data' => $timetable,
        ];
    }

    /**
     * المعلم يرى جدول حصصه الأسبوعي
     */
    public function mySchedule()
    {
        $user = auth()->user();
        $teacher = $user->teacher;

        if (!$teacher) {
            return response()->json(['message' => __('Teacher profile not found.')], 404);
        }

        $schedules = Schedule::where('teacher_id', $teacher->id)
            ->with([
                'subject',
                'timetable.section.level',
                'timetable.section.program',
            ])
            ->get()
            ->groupBy('day');

        return response()->json([
            'message' => __('Schedule retrieved successfully'),
            'data' => $schedules,
        ]);
    }
}
