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
use Illuminate\Support\Facades\Hash;

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
     * 3️⃣ عرض قائمة الأبناء المسجلين وبياناتهم الأساسية (مشروط بالموافقة)
     * GET /api/parent/children
     */
    public function myChildren()
    {
        if (auth()->user()->role !== 'parent') {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $parent = auth()->user()->parent;

        if (!$parent || $parent->status !== 'approved') {
            return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
        }

        $children = $parent->students()
            ->with([
                'user',
                'enrollments' => fn ($query) => $query->latest(),
                'enrollments.level',
                'enrollments.section',
                'enrollments.program',
            ])
            ->get();

        return response()->json([
            'message' => __('Children retrieved successfully'),
            'data' => $children,
        ]);
    }

    /**
     * 4️⃣ جلب درجات الأبناء حصراً (مشروط بالموافقة)
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
     * 5️⃣ تتبع غياب الأبناء بناءً على الأيام المفقودة (مشروط بالموافقة)
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

    // ══════════════════════════════════════════════════════════════
    // إدارة حسابات أولياء الأمور من قِبل الأدمن (Admin Parent Accounts Management)
    // ══════════════════════════════════════════════════════════════

    /**
     * 6️⃣ الإضافة المباشرة من الأدمن لحساب أب جديد (يُقبل فوراً، مع إمكانية ربط أبناء مباشرة)
     * POST /api/parents
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can create parent accounts directly')], 403);
        }

        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'password' => 'required|string|min:8',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
        ]);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => 'parent',
        ]);

        $parent = ParentModel::create([
            'user_id' => $user->id,
            'status' => 'approved',
            'phone_number' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
        ]);

        if (!empty($data['student_ids'])) {
            Student::whereIn('id', $data['student_ids'])->update(['parent_id' => $parent->id]);
        }

        return response()->json([
            'message' => __('Parent account created successfully by admin'),
            'data' => $parent->load(['user', 'students.user', 'wallet']),
        ], 201);
    }

    /**
     * 7️⃣ عرض جميع حسابات أولياء الأمور مع بحث وفلترة (الأدمن فقط)
     * GET /api/admin/parents
     */
    public function index(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view parent accounts')], 403);
        }

        $data = $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|in:pending,approved,rejected',
        ]);

        $query = ParentModel::with(['user', 'students.user']);

        if ($data['search'] ?? null) {
            $search = $data['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($data['status'] ?? null) {
            $query->where('status', $data['status']);
        }

        $parents = $query->latest()->get();

        return response()->json([
            'message' => __('Parent accounts retrieved successfully'),
            'data' => $parents,
        ]);
    }

    /**
     * 8️⃣ عرض حساب أب محدد بكل تفاصيله (الأدمن فقط)
     * GET /api/admin/parents/{parent}
     */
    public function show(ParentModel $parent)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view parent account details')], 403);
        }

        return response()->json([
            'message' => __('Parent account retrieved successfully'),
            'data' => $parent->load(['user', 'students.user', 'wallet']),
        ]);
    }

    /**
     * 9️⃣ التعديل المباشر من الأدمن (بيانات الحساب + إعادة ربط الأبناء)
     * PUT/PATCH /api/admin/parents/{parent}
     */
    public function update(Request $request, ParentModel $parent)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can update parent accounts directly')], 403);
        }

        $data = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|string|email|max:255|unique:users,email,' . $parent->user_id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8',
            'status' => 'nullable|in:pending,approved,rejected',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
        ]);

        $parent->user->update(array_filter([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'password' => isset($data['password']) ? Hash::make($data['password']) : null,
        ]));

        $parent->update(array_filter([
            'phone_number' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'status' => $data['status'] ?? null,
        ]));

        // إعادة ربط الأبناء فقط إذا أرسل الأدمن student_ids صراحةً (حتى لو مصفوفة فارغة لفكّ الربط عن الجميع)
        if (array_key_exists('student_ids', $data)) {
            Student::where('parent_id', $parent->id)->update(['parent_id' => null]);
            Student::whereIn('id', $data['student_ids'] ?? [])->update(['parent_id' => $parent->id]);
        }

        return response()->json([
            'message' => __('Parent account updated successfully by admin'),
            'data' => $parent->load(['user', 'students.user', 'wallet']),
        ]);
    }

    /**
     * 🔟 الحذف المباشر من الأدمن (يفكّ ربط الأبناء تلقائياً بدل حذفهم)
     * DELETE /api/admin/parents/{parent}
     */
    public function destroy(ParentModel $parent)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can delete parent accounts directly')], 403);
        }

        $parent->loadMissing('wallet');

        if ($parent->wallet && (float) $parent->wallet->balance > 0) {
            return response()->json([
                'error' => __('This parent wallet still has a positive balance. Withdraw or transfer it before deleting the account.'),
            ], 422);
        }

        // حذف حساب المستخدم يحذف سجل الأب تلقائياً (cascade)، ويفكّ ربط أبنائه (nullOnDelete) دون حذفهم
        $parent->user->delete();

        return response()->json([
            'message' => __('Parent account deleted successfully by admin'),
        ]);
    }
}
