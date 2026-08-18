<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * Display a listing of schedule entries.
     */
    public function index()
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        $query = Schedule::with(['timetable.section', 'subject', 'teacher.user']);

        if (auth()->user()->role === 'teacher') {
            $query->where('teacher_id', auth()->user()->teacher->id);
        }

        return response()->json([
            'message' => __('Schedules retrieved successfully'),
            'data' => $query->get()
        ]);
    }

    /**
     * Store a newly created schedule entry.
     */
    public function store(Request $request)
    {
        dd('تعديلي يظهر هنا');
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can create schedule entries')], 403);
        }

        $data = $request->validate([
            'timetable_id' => 'required|exists:timetables,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'required|exists:teachers,id',
            'day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday',
            'period' => 'required|integer|min:1|max:7',
        ]);

        $existing = Schedule::where('timetable_id', $data['timetable_id'])
            ->where('day', $data['day'])
            ->where('period', $data['period'])
            ->first();

        if ($existing) {
            return response()->json([
                'error' => __('This period already has a scheduled subject on that day')
            ], 422);
        }

        $schedule = Schedule::create($data);

        return response()->json([
            'message' => __('Schedule entry created successfully'),
            'data' => $schedule->load(['timetable.section', 'subject', 'teacher.user'])
        ], 201);
    }

    /**
     * Display the specified schedule entry.
     */
    public function show(Schedule $schedule)
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        if (auth()->user()->role === 'teacher' && auth()->user()->teacher->id !== $schedule->teacher_id) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json([
            'message' => __('Schedule entry retrieved successfully'),
            'data' => $schedule->load(['timetable.section', 'subject', 'teacher.user'])
        ]);
    }

    /**
     * Update the specified schedule entry.
     */
    public function update(Request $request, Schedule $schedule)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can update schedule entries')], 403);
        }

        $data = $request->validate([
            'subject_id' => 'nullable|exists:subjects,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'day' => 'nullable|string|in:sunday,monday,tuesday,wednesday,thursday',
            'period' => 'nullable|integer|min:1|max:7',
        ]);

        if (!empty($data['day']) || !empty($data['period'])) {
            $newDay = $data['day'] ?? $schedule->day;
            $newPeriod = $data['period'] ?? $schedule->period;

            $existing = Schedule::where('timetable_id', $schedule->timetable_id)
                ->where('day', $newDay)
                ->where('period', $newPeriod)
                ->where('id', '!=', $schedule->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'error' => __('This period already has a scheduled subject on that day')
                ], 422);
            }
        }

        $schedule->update(array_filter([
            'subject_id' => $data['subject_id'] ?? null,
            'teacher_id' => $data['teacher_id'] ?? null,
            'day' => $data['day'] ?? null,
            'period' => $data['period'] ?? null,
        ]));

        $schedule->load(['timetable.section', 'subject', 'teacher.user']);

        $this->notifications->timetableChanged(
            $schedule->timetable->section ?? null,
            $schedule->teacher,
            __('A period in the timetable has been modified.'),
            ['schedule_id' => $schedule->id],
        );

        return response()->json([
            'message' => __('Schedule entry updated successfully'),
            'data' => $schedule
        ]);
    }

    /**
     * Remove the specified schedule entry.
     */
    public function destroy(Schedule $schedule)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can delete schedule entries')], 403);
        }

        $schedule->load(['timetable.section', 'teacher']);
        $section = $schedule->timetable->section ?? null;
        $teacher = $schedule->teacher;

        $schedule->delete();

        $this->notifications->timetableChanged(
            $section,
            $teacher,
            __('A period has been removed from the timetable.'),
            ['schedule_id' => $schedule->id],
        );

        return response()->json([
            'message' => __('Schedule entry deleted successfully')
        ]);
    }
}
