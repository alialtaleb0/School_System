<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    /**
     * عرض كل العطل الرسمية (يمكن لأي مستخدم مسجّل رؤيتها، مفيدة للجداول والتقويم).
     * GET /api/holidays
     */
    public function index()
    {
        $holidays = Holiday::orderBy('from_date')->get();

        return response()->json(['data' => $holidays]);
    }

    /**
     * إضافة عطلة رسمية جديدة (الأدمن فقط).
     * POST /api/holidays
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can add holidays.')], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $holiday = Holiday::create([
            'name' => $data['name'],
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => __('Holiday added successfully'),
            'data' => $holiday,
        ], 201);
    }

    /**
     * تعديل عطلة رسمية (الأدمن فقط).
     * PUT/PATCH /api/holidays/{holiday}
     */
    public function update(Request $request, Holiday $holiday)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can update holidays.')], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'from_date' => 'sometimes|required|date',
            'to_date' => 'sometimes|required|date|after_or_equal:from_date',
        ]);

        $holiday->update($data);

        return response()->json([
            'message' => __('Holiday updated successfully'),
            'data' => $holiday,
        ]);
    }

    /**
     * حذف عطلة رسمية (الأدمن فقط).
     * DELETE /api/holidays/{holiday}
     */
    public function destroy(Holiday $holiday)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can delete holidays.')], 403);
        }

        $holiday->delete();

        return response()->json(['message' => __('Holiday deleted successfully')]);
    }
}
