<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ParentModel;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Attendance;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ParentController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * 1️⃣ إكمال الملف الشخصي (خاص بالأب بعد تسجيل الحساب مباشرة)
     * يستقبل فقط مصفوفة الأبناء "student_ids"
     * POST /api/parent/complete-profile
     */
    public function completeProfile(Request $request)
    {
        // التأكد من أن المستخدم الحالي هو أب
        if (auth()->user()->role !== 'parent') {
            return response()->json(['error' => __('Unauthorized. Only parents can access this.')], 403);
        }

        $user = auth()->user();

        // التحقق من البيانات القادمة من الأب (المصفوفة فقط)
        $data = $request->validate([
            'student_ids'  => 'required|array', // معرفات الأبناء المطلوب ربطهم
            'student_ids.*'=> 'exists:students,id', // التأكد من وجود كل id في جدول الطلاب
        ]);

        // البحث عن سجل الأب المرتبط بالمستخدم أو إنشاؤه إن لم يكن موجوداً
        $parent = ParentModel::firstOrNew(['user_id' => $user->id]);

        // حفظ البيانات وجعل الحالة معلقة بانتظار موافقة الأدمن
        $parent->fill([
            'status'              => 'pending', // إعادة الحالة لمعلق في حال قام بالتحديث
            'pending_student_ids' => $data['student_ids'], // حفظ الأبناء مؤقتاً هنا

            ]);

        $parent->save();

        return response()->json([
            'message' => __('Profile details submitted successfully. Waiting for admin approval.'),
            'parent'  => $parent
        ], 200);
    }

    /**
     * 2️⃣ موافقة أو رفض الأدمن على الملف الشخصي للأب
     * POST /api/admin/parents/{id}/review
     */
    public function reviewProfile(Request $request, $id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. Only admins can approve profiles.')], 403);
        }

        $request->validate([
            'action' => 'required|in:approve,reject'
        ]);

        $parent = ParentModel::findOrFail($id);

        if ($request->action === 'approve') {

            // تحقق من وجود طلبات ربط أبناء مؤقتة
            if ($parent->pending_student_ids && is_array($parent->pending_student_ids)) {

                DB::beginTransaction();
                try {
                    // الربط الرسمي: تحديث الـ parent_id في جدول الطلاب للأبناء المقبولين
                    Student::whereIn('id', $parent->pending_student_ids)->update([
                        'parent_id' => $parent->id
                    ]);

                    // تحديث حالة الأب وتفريغ المصفوفة المؤقتة
                    $parent->update([
                        'status' => 'approved',
                        'pending_student_ids' => null
                    ]);

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => __('Approval failed.')], 500);
                }
            } else {
                // إذا وافق الأدمن ولم يكن هناك أبناء محددين
                $parent->update(['status' => 'approved']);
            }

            $this->notifications->parentAccountReviewed($parent->fresh(), 'approved');

            return response()->json(['message' => __('Parent profile approved and students linked officially.')]);
        }

        // في حال الرفض
        $parent->update(['status' => 'rejected']);

        $this->notifications->parentAccountReviewed($parent, 'rejected');

        return response()->json(['message' => __('Parent profile has been rejected.')]);
    }

    /**
     * 3️⃣ جلب درجات الأبناء حصراً (مشروط بالموافقة)
     * GET /api/parent/grades
     */
    public function getChildrenGrades()
    {
        if (auth()->user()->role !== 'parent') {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $parent = auth()->user()->parent;

        // حماية البيانات: إذا كان الحساب غير مقبول لن يرى أي شيء
        if (!$parent || $parent->status !== 'approved') {
            return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
        }

        $grades = Grade::whereHas('student', function ($query) use ($parent) {
            $query->where('parent_id', $parent->id);
        })->with(['student.user', 'exam.subject', 'teacher.user'])->latest()->get();

        return response()->json(['data' => $grades], 200);
    }

    /**
     * 4️⃣ تتبع غياب الأبناء بناءً على الأيام المفقودة (مشروط بالموافقة)
     * GET /api/parent/attendance
     */
    public function getChildrenAttendance()
    {
        if (auth()->user()->role !== 'parent') {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $parent = auth()->user()->parent;

        // حماية البيانات: منع الحسابات المعلقة من تتبع الطلاب
        if (!$parent || $parent->status !== 'approved') {
            return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
        }

        $parent->load('students.user');
        $result = [];
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();

        foreach ($parent->students as $student) {
            $attendedDates = Attendance::where('student_id', $student->id)
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->pluck('date')
                ->toArray();

            $absentDates = [];

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                if ($date->isFriday() || $date->isSaturday()) {
                    continue;
                }
                $currentDateString = $date->toDateString();
                if (!in_array($currentDateString, $attendedDates)) {
                    $absentDates[] = $currentDateString;
                }
            }

            rsort($absentDates);

            $result[] = [
                'student_id'        => $student->id,
                'student_name'      => $student->user->name,
                'total_absent_days' => count($absentDates),
                'absent_dates'      => $absentDates
            ];
        }

        return response()->json(['data' => $result], 200);
    }
}
