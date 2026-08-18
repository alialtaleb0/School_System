<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        $subjects = Subject::with(['teachers.user', 'levels'])
            ->get(['id', 'name']);

        return response()->json([
            'message' => __('Subjects retrieved successfully'),
            'data' => $subjects
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can create subjects')], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:subjects,name',
            'teacher_ids' => 'nullable|array',
            'teacher_ids.*' => 'exists:teachers,id'
        ]);

        $subject = Subject::create([
            'name' => $data['name'],
        ]);

        if (!empty($data['teacher_ids'])) {
            $subject->teachers()->sync($data['teacher_ids']);
        }

        return response()->json([
            'message' => __('Subject created successfully'),
            'data' => $subject->load('teachers.user')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Subject $subject)
    {
        if (!in_array(auth()->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json([
            'message' => __('Subject retrieved successfully'),
            'data' => $subject->load(['teachers.user', 'levels'])
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subject $subject)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can update subjects')], 403);
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:255|unique:subjects,name,' . $subject->id,
            'teacher_ids' => 'nullable|array',
            'teacher_ids.*' => 'exists:teachers,id'
        ]);

        $updateData = [];

        if (!empty($data['name'])) {
            $updateData['name'] = $data['name'];
        }

        if (!empty($updateData)) {
            $subject->update($updateData);
        }

        if (array_key_exists('teacher_ids', $data)) {
            $subject->teachers()->sync($data['teacher_ids'] ?? []);
        }

        return response()->json([
            'message' => __('Subject updated successfully'),
            'data' => $subject->load('teachers.user', 'levels')
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subject $subject)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can delete subjects')], 403);
        }

        $subject->delete();

        return response()->json([
            'message' => __('Subject deleted successfully')
        ]);
    }
}
