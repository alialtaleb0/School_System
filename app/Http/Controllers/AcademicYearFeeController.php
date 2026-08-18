<?php

namespace App\Http\Controllers;

use App\Models\AcademicYearFee;
use Illuminate\Http\Request;

/**
 * إدارة رسوم التسجيل لكل سنة دراسية - بإذن الله تعالى
 * رسوم موحّدة لكل من يسجّل في سنة دراسية معينة، تُخصم من محفظة ولي الأمر
 * تلقائياً عند اعتماد الأدمن لطلب التسجيل.
 */
class AcademicYearFeeController extends Controller
{
    /**
     * 1️⃣ عرض رسوم جميع السنوات الدراسية المحددة
     * GET /api/academic-year-fees
     * (متاح لأي مستخدم مسجّل دخول، مفيد ليرى ولي الأمر/الطالب الرسوم قبل التسجيل)
     */
    public function index()
    {
        return response()->json([
            'data' => AcademicYearFee::orderByDesc('academic_year')->get(),
        ]);
    }

    /**
     * 2️⃣ تحديد أو تعديل رسوم سنة دراسية معينة (الأدمن فقط)
     * POST /api/admin/academic-year-fees
     * body: { academic_year: "2026-2027", fee: 500 }
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. Only admins can set academic year fees.')], 403);
        }

        $data = $request->validate([
            'academic_year' => 'required|string|max:20',
            'fee' => 'required|numeric|min:0',
        ]);

        $record = AcademicYearFee::updateOrCreate(
            ['academic_year' => $data['academic_year']],
            ['fee' => $data['fee']]
        );

        return response()->json([
            'message' => __('Academic year fee saved successfully'),
            'data' => $record,
        ]);
    }

    /**
     * 3️⃣ حذف رسوم سنة دراسية (تعود مجانية) (الأدمن فقط)
     * DELETE /api/admin/academic-year-fees/{id}
     */
    public function destroy($id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. Only admins can delete academic year fees.')], 403);
        }

        AcademicYearFee::findOrFail($id)->delete();

        return response()->json(['message' => __('Academic year fee removed successfully')]);
    }
}
