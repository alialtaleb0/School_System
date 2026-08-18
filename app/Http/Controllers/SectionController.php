<?php

namespace App\Http\Controllers;

use App\Models\Level;
use App\Models\Program;
use App\Models\Section;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    /**
     * عرض جميع الشعب مع المرحلة والمسار
     */
    public function index()
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher', 'student'])) {
            return response()->json(['error' => __('Only admins can view sections')], 403);
        }

        $sections = Section::with(['level', 'program'])->get();

        return response()->json([
            'message' => __('Sections retrieved successfully'),
            'data'    => $sections,
        ]);
    }

    /**
     * إنشاء شعبة جديدة
     * يجب تحديد: الاسم، المرحلة (level_id)، والمسار (program_id) اختياري
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can create sections')], 403);
        }

        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'capacity'   => 'nullable|integer|min:1',
            'level_id'   => 'required|exists:levels,id',
            'program_id' => 'nullable|exists:programs,id',
        ]);

        // التحقق: المسار (Scientific/Literary) يُطبَّق فقط على Grade 10 وما فوق
        if (!empty($data['program_id'])) {
            $level = Level::findOrFail($data['level_id']);
            $gradeNumber = (int) filter_var($level->name, FILTER_SANITIZE_NUMBER_INT);

            if ($gradeNumber < 10) {
                return response()->json([
                    'error' => __('Programs (Scientific/Literary) are only applicable from Grade 10 onwards.'),
                ], 422);
            }
        }

        $section = Section::create($data);

        return response()->json([
            'message' => __('Section created successfully'),
            'data'    => $section->load(['level', 'program']),
        ], 201);
    }

    /**
     * عرض شعبة محددة
     */
    public function show(Section $section)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view sections')], 403);
        }

        return response()->json([
            'message' => __('Section retrieved successfully'),
            'data'    => $section->load(['level', 'program', 'timetables']),
        ]);
    }

    /**
     * تعديل شعبة
     */
    public function update(Request $request, Section $section)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can update sections')], 403);
        }

        $data = $request->validate([
            'name'       => 'nullable|string|max:255',
            'capacity'   => 'nullable|integer|min:1',
            'level_id'   => 'nullable|exists:levels,id',
            'program_id' => 'nullable|exists:programs,id',
        ]);

        // نفس التحقق عند التعديل
        $levelId = $data['level_id'] ?? $section->level_id;
        if (!empty($data['program_id'])) {
            $level = Level::findOrFail($levelId);
            $gradeNumber = (int) filter_var($level->name, FILTER_SANITIZE_NUMBER_INT);

            if ($gradeNumber < 10) {
                return response()->json([
                    'error' => __('Programs (Scientific/Literary) are only applicable from Grade 10 onwards.'),
                ], 422);
            }
        }

        $section->update(array_filter($data, fn($v) => !is_null($v)));

        return response()->json([
            'message' => __('Section updated successfully'),
            'data'    => $section->load(['level', 'program']),
        ]);
    }

    /**
     * حذف شعبة
     */
    public function destroy(Section $section)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can delete sections')], 403);
        }

        $section->delete();

        return response()->json([
            'message' => __('Section deleted successfully'),
        ]);
    }
}
