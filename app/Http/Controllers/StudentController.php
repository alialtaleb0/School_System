<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * 1️⃣ طالب يكمل ملفه الشخصي - ينشئ سجل Student
     * POST /api/students/complete-profile
     */
    public function completeProfile(Request $request)
    {
        $user = auth()->user();

        // التحقق أن المستخدم ليس لديه ملف طالب مسبقاً
        if ($user->student) {
            return response()->json([
                'error' => __('Your student profile already exists')
            ], 422);
        }

        // التحقق من أنه طالب
        if ($user->role !== 'student') {
            return response()->json([
                'error' => __('Only students can complete this profile')
            ], 403);
        }

        // validation
        $data = $request->validate([
            'student_number' => 'nullable|string|unique:students,student_number',
            'date_of_birth' => 'nullable|date',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $profileImagePath = null;

        // معالجة رفع الصورة الشخصية
        if ($request->hasFile('profile_image')) {
            $profileImagePath = $request->file('profile_image')->store(
                'students/profile_images',
                'public'
            );
        }

        // إنشاء سجل Student
        $student = Student::create([
            'user_id' => $user->id,
            'student_number' => $data['student_number'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'profile_image' => $profileImagePath,
        ]);

        return response()->json([
            'message' => __('Student profile created successfully'),
            'data' => $student
        ], 201);
    }

    /**
     * 2️⃣ الأدمن ينشئ طالب جديد مباشرة
     * POST /api/students
     */
    public function store(Request $request)
    {
        // التحقق من صلاحيات الأدمن
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can create students')
            ], 403);
        }

        // validation
        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'password' => 'required|string|min:8',
            'student_number' => 'nullable|string|unique:students,student_number',
        ]);

        // إنشاء User
        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address' => $data['address'],
            'password' => Hash::make($data['password']),
            'role' => 'student'
        ]);

        // إنشاء Student
        $student = Student::create([
            'user_id' => $user->id,
            'student_number' => $data['student_number'] ?? null,
        ]);

        return response()->json([
            'message' => __('Student created successfully'),
            'data' => [
                'user' => $user,
                'student' => $student
            ]
        ], 201);
    }

    /**
     * 3️⃣ عرض جميع الطلاب مع البحث والفلترة (للأدمن فقط)
     * GET /api/students?search=أحمد&level_id=1&section_id=2
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // التحقق من الصلاحيات: الأدمن والمعلم وولي الأمر
        if (!in_array($user->role, ['admin', 'teacher', 'parent'])) {
            return response()->json([
                'error' => __('Only admins, teachers and parents can view students')
            ], 403);
        }

        // Validation
        $data = $request->validate([
            'search' => 'nullable|string|max:255',
            'level_id' => 'nullable|exists:levels,id',
            'section_id' => 'nullable|exists:sections,id',
            'student_number' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = Student::with('user', 'enrollments', 'attendance', 'grades');

        // 🔒 التقييد: المعلم يرى طلابه الفعليين فقط، وولي الأمر يرى أبناءه فقط
        if ($user->role === 'teacher') {
            if (!$user->teacher) {
                return response()->json(['error' => __('No teacher profile linked to your account.')], 403);
            }

            $query->whereIn('id', $user->teacher->scheduledStudents()->pluck('id'));
        } elseif ($user->role === 'parent') {
            if (!$user->parent || $user->parent->status !== 'approved') {
                return response()->json(['error' => __('Your account is pending approval or has been rejected.')], 403);
            }

            $query->where('parent_id', $user->parent->id);
        }

        // 🔍 البحث بالاسم الأول أو الأخير أو البريد الإلكتروني
        if ($data['search'] ?? null) {
            $search = $data['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // 🔍 البحث برقم الطالب
        if ($data['student_number'] ?? null) {
            $query->where('student_number', 'like', "%{$data['student_number']}%");
        }

        // 📊 فلترة حسب المستوى
        if ($data['level_id'] ?? null) {
            $query->whereHas('enrollments', function ($q) use ($data) {
                $q->where('level_id', $data['level_id'])
                  ->where('status', 'approved');
            });
        }

        // 📚 فلترة حسب الشعبة
        if ($data['section_id'] ?? null) {
            $query->whereHas('enrollments', function ($q) use ($data) {
                $q->where('section_id', $data['section_id'])
                  ->where('status', 'approved');
            });
        }

        $perPage = $data['per_page'] ?? 15;
        $students = $query->paginate($perPage);

        return response()->json([
            'message' => __('Students retrieved successfully'),
            'filters' => [
                'search' => $data['search'] ?? null,
                'level_id' => $data['level_id'] ?? null,
                'section_id' => $data['section_id'] ?? null,
                'student_number' => $data['student_number'] ?? null,
            ],
            'data' => $students
        ]);
    }

    /**
     * 4️⃣ عرض بيانات طالب محدد
     * GET /api/students/{id}
     */
    public function show($id)
    {
        $student = Student::with([
            'user',
            'enrollments.level',
            'enrollments.section',
            'enrollments.program',
            'grades.exam.subject',
            'attendance' => fn ($query) => $query->latest('date')->limit(30),
            'homeworkSubmissions.homework.subject',
            'certificates',
        ])->findOrFail($id);

        $user = auth()->user();

        // التحقق: الطالب يرى بياناته فقط، المعلم يرى طلابه الفعليين فقط،
        // وليّ الأمر يرى أبناءه فقط، والأدمن يرى الجميع
        $isAuthorized = match (true) {
            $user->role === 'admin' => true,
            $user->role === 'student' => $user->id === $student->user_id,
            $user->role === 'teacher' => (bool) ($user->teacher && $user->teacher->teaches($student)),
            $user->role === 'parent' => (bool) (
                $user->parent
                && $user->parent->status === 'approved'
                && $student->parent_id === $user->parent->id
            ),
            default => false,
        };

        if (! $isAuthorized) {
            return response()->json([
                'error' => __('Unauthorized')
            ], 403);
        }

        return response()->json([
            'message' => __('Student retrieved successfully'),
            'data' => $student
        ]);
    }

    /**
     * 4️⃣ الأدمن يعدّل بيانات طالب موجود مباشرة
     * PUT/PATCH /api/students/{id}
     */
    public function update(Request $request, $id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can update students')
            ], 403);
        }

        $student = Student::with('user')->findOrFail($id);

        $data = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|string|email|max:255|unique:users,email,' . $student->user_id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8',
            'student_number' => 'nullable|string|unique:students,student_number,' . $student->id,
            'date_of_birth' => 'nullable|date',
            'parent_id' => 'nullable|exists:parents,id',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $student->user->update(array_filter([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'password' => isset($data['password']) ? Hash::make($data['password']) : null,
        ]));

        $studentUpdates = [];

        if (array_key_exists('student_number', $data)) {
            $studentUpdates['student_number'] = $data['student_number'];
        }

        if (array_key_exists('date_of_birth', $data)) {
            $studentUpdates['date_of_birth'] = $data['date_of_birth'];
        }

        // تُحدَّث فقط إذا أرسلها الأدمن صراحةً، حتى لو null (لفكّ ربط الطالب عن وليّ أمره)
        if (array_key_exists('parent_id', $data)) {
            $studentUpdates['parent_id'] = $data['parent_id'];
        }

        if ($request->hasFile('profile_image')) {
            $oldImage = $student->getRawOriginal('profile_image');
            if ($oldImage) {
                Storage::disk('public')->delete($oldImage);
            }
            $studentUpdates['profile_image'] = $request->file('profile_image')->store('students/profile_images', 'public');
        }

        if (!empty($studentUpdates)) {
            $student->update($studentUpdates);
        }

        return response()->json([
            'message' => __('Student updated successfully'),
            'data' => $student->fresh(['user', 'enrollments.level', 'enrollments.section']),
        ]);
    }

    /**
     * 5️⃣ حذف طالب (للأدمن فقط)
     * DELETE /api/students/{id}
     */
    public function destroy($id)
    {
        // التحقق من صلاحيات الأدمن
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can delete students')
            ], 403);
        }

        $student = Student::findOrFail($id);

        // حذف User أيضاً (سيحذف Student تلقائياً بسبب cascadeOnDelete)
        $user = $student->user;
        $user->delete();

        return response()->json([
            'message' => __('Student deleted successfully')
        ]);
    }

    /**
     * 6️⃣ عرض بيانات الطالب الحالي
     * GET /api/me
     */
    public function me()
    {
        $user = auth()->user();

        return response()->json([
            'message' => __('Current user retrieved successfully'),
            'data' => $user->load([
                'student.enrollments.level',
                'student.enrollments.section'
            ])
        ]);
    }

    /**
     * 7️⃣ تسجيل الطالب في صف + شعبة (Pending)
     * POST /api/enroll
     */
    public function enroll(Request $request)
    {
        $user = auth()->user();

        // تأكد أنه طالب
        if ($user->role !== 'student') {
            return response()->json([
                'error' => __('Only students can enroll')
            ], 403);
        }

        // validation
        $data = $request->validate([
            'level_id' => 'required|exists:levels,id',
            'section_id' => 'required|exists:sections,id',
            'academic_year' => 'required|string'
        ]);

        $student = $user->student;

        if (!$student) {
            return response()->json([
                'error' => __('Student profile not found. Please complete your profile first.')
            ], 404);
        }

        // 🔥 1. تحقق أن الشعبة تتبع الصف
        $section = Section::where('id', $data['section_id'])
            ->where('level_id', $data['level_id'])
            ->first();

        if (!$section) {
            return response()->json([
                'error' => __('Invalid section for selected level')
            ], 422);
        }

        // 🔥 2. تحقق من الامتلاء (نحسب فقط المقبولين)
        $count = StudentEnrollment::where('section_id', $section->id)
            ->where('status', 'approved')
            ->count();

        if ($count >= $section->capacity) {
            return response()->json([
                'error' => __('Section is full')
            ], 422);
        }

        // 🔥 3. منع التكرار بنفس السنة
        $exists = StudentEnrollment::where('student_id', $student->id)
            ->where('academic_year', $data['academic_year'])
            ->exists();

        if ($exists) {
            return response()->json([
                'error' => __('You already applied for this academic year')
            ], 422);
        }

        // 🔥 4. إنشاء الطلب (Pending)
        $enrollment = StudentEnrollment::create([
            'student_id' => $student->id,
            'level_id' => $data['level_id'],
            'section_id' => $section->id,
            'academic_year' => $data['academic_year'],
            'student_number' => null,
            'status' => 'pending',
        ]);

        $this->notifications->enrollmentRequested($enrollment);

        return response()->json([
            'message' => __('Enrollment request submitted, waiting for admin approval'),
            'data' => $enrollment
        ]);
    }

    /**
     * 8️⃣ عرض طلبات الطالب
     * GET /api/my-enrollments
     */
    public function myEnrollments()
    {
        $user = auth()->user();

        if (!$user->student) {
            return response()->json(['error' => __('Student profile not found')], 404);
        }

        return response()->json([
            'message' => __('Your enrollments retrieved successfully'),
            'data' => $user->student->enrollments()
                ->with(['level', 'section'])
                ->latest()
                ->get()
        ]);
    }

    /**
     * 9️⃣ إلغاء طلب (إذا لسا pending)
     * DELETE /api/enrollments/{id}
     */
    public function cancel($id)
    {
        $user = auth()->user();

        if (!$user->student) {
            return response()->json(['error' => __('Student profile not found')], 404);
        }

        $enrollment = StudentEnrollment::where('id', $id)
            ->where('student_id', $user->student->id)
            ->firstOrFail();

        if ($enrollment->status !== 'pending') {
            return response()->json([
                'error' => __('Only pending requests can be cancelled')
            ], 422);
        }

        $enrollment->delete();

        return response()->json([
            'message' => __('Enrollment cancelled')
        ]);
    }

    /**
     * 🔟 الأدمن يوافق على طلب التحاق (Approve)
     * PUT /api/enrollments/{id}/approve
     */
    public function approveEnrollment(Request $request, $id)
    {
        // التحقق من صلاحيات الأدمن
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can approve enrollments')
            ], 403);
        }

        $enrollment = StudentEnrollment::with(['student.parent.wallet'])->findOrFail($id);

        // التحقق من أن الطلب في حالة pending
        if ($enrollment->status !== 'pending') {
            return response()->json([
                'error' => __('Only pending requests can be approved. Current status: :status', ['status' => $enrollment->status])
            ], 422);
        }

        // validation - رقم الطالب اختياري
        $data = $request->validate([
            'student_number' => 'nullable|string|unique:students,student_number'
        ]);

        // 💰 رسوم السنة الدراسية (موحّدة لكل من يسجّل في نفس السنة) تُخصم من محفظة ولي أمر الطالب
        $fee = \App\Models\AcademicYearFee::feeFor($enrollment->academic_year);

        if ($fee > 0) {
            $parent = $enrollment->student->parent;

            if (!$parent) {
                return response()->json([
                    'error' => __('This student is not linked to a parent yet. Cannot charge enrollment fee.')
                ], 422);
            }

            if (!$parent->wallet) {
                $parent->wallet()->create(['balance' => 0]);
                $parent->refresh();
            }

            try {
                DB::transaction(function () use ($parent, $fee, $enrollment, $data) {
                    $parent->wallet->withdraw(
                        amount: $fee,
                        description: "Enrollment fee - {$enrollment->academic_year}",
                        performedBy: auth()->user(),
                        method: 'manual',
                        referenceType: 'student_enrollment',
                        referenceId: $enrollment->id,
                        type: 'payment',
                    );

                    $enrollment->update([
                        'status' => 'approved',
                        'student_number' => $data['student_number'] ?? null,
                    ]);
                });
            } catch (InsufficientWalletBalanceException $e) {
                return response()->json([
                    'error' => __('Cannot approve enrollment. Parent wallet balance is insufficient to cover the program fee.'),
                    'required_fee' => $e->required,
                    'available_balance' => $e->available,
                ], 422);
            }
        } else {
            // البرنامج مجاني - لا حاجة لأي خصم
            $enrollment->update([
                'status' => 'approved',
                'student_number' => $data['student_number'] ?? null,
            ]);
        }

        $this->notifications->enrollmentStatusChanged($enrollment->fresh(), 'approved');

        return response()->json([
            'message' => __('Enrollment approved successfully'),
            'data' => $enrollment->fresh(['student.parent.wallet'])
        ]);
    }

    /**
     * 1️⃣1️⃣ الأدمن يرفض طلب التحاق (Reject)
     * PUT /api/enrollments/{id}/reject
     */
    public function rejectEnrollment(Request $request, $id)
    {
        // التحقق من صلاحيات الأدمن
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can reject enrollments')
            ], 403);
        }

        $enrollment = StudentEnrollment::findOrFail($id);

        // التحقق من أن الطلب في حالة pending
        if ($enrollment->status !== 'pending') {
            return response()->json([
                'error' => __('Only pending requests can be rejected. Current status: :status', ['status' => $enrollment->status])
            ], 422);
        }

        // تحديث الطلب
        $enrollment->update([
            'status' => 'rejected'
        ]);

        $this->notifications->enrollmentStatusChanged($enrollment, 'rejected');

        return response()->json([
            'message' => __('Enrollment rejected successfully'),
            'data' => $enrollment
        ]);
    }

    /**
     * 1️⃣2️⃣ الأدمن يعرض جميع طلبات التحاق (للموافقة)
     * GET /api/enrollments?status=pending
     */
    public function listEnrollments(Request $request)
    {
        // التحقق من صلاحيات الأدمن
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => __('Only admins can view all enrollments')
            ], 403);
        }

        // validation
        $data = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected'
        ]);

        $query = StudentEnrollment::with(['student.user', 'level', 'section']);

        // فلتر حسب الحالة إن وجدت
        if ($data['status'] ?? null) {
            $query->where('status', $data['status']);
        }

        $enrollments = $query->latest()->get();

        return response()->json([
            'message' => __('Enrollments retrieved successfully'),
            'data' => $enrollments
        ]);
    }
}
