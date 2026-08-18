<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class TeacherController extends Controller
{
    /**
     * 1️⃣ المعلم يكمل ملفه الشخصي (مع إضافة صورة اختيارية)
     * POST /api/teachers/complete-profile
     */
    public function completeProfile(Request $request)
    {
        $user = auth()->user();

        if ($user->teacher) {
            return response()->json(['error' => __('Your teacher profile already exists')], 422);
        }

        if ($user->role !== 'teacher') {
            return response()->json(['error' => __('Only teachers can complete this profile')], 403);
        }

        $data = $request->validate([
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'exists:subjects,id',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048', // صورة اختيارية
        ]);

        // رفع الصورة إذا تم إرفاقها
        $imagePath = null;
        if ($request->hasFile('profile_image')) {
            $imagePath = $request->file('profile_image')->store('teachers/profiles', 'public');
        }

        $teacher = Teacher::create([
            'user_id' => $user->id,
            'profile_image' => $imagePath,
            'status' => 'pending', // الحالة الافتراضية بانتظار الموافقة
            'pending_action' => 'none'
        ]);

        if (!empty($data['subject_ids'])) {
            $teacher->subjects()->sync($data['subject_ids']);
        }

        return response()->json([
            'message' => __('Teacher profile creation requested successfully. Pending admin approval.'),
            'data' => $teacher->load(['user', 'subjects'])
        ], 201);
    }

    /**
     * 2️⃣ طلب المعلم تعديل بياناته (يرسل طلب للأدمن)
     * POST /api/teachers/request-update
     */
    public function requestUpdate(Request $request)
    {
        $user = auth()->user();
        $teacher = $user->teacher;

        if (!$teacher || $teacher->status !== 'approved') {
            return response()->json(['error' => __('You must have an approved profile to request updates')], 403);
        }

        $data = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'exists:subjects,id'
        ]);

        // حفظ التعديلات في الحقل المؤقت
        $teacher->update([
            'pending_action' => 'update',
            'pending_data' => json_encode($data) // تحويل البيانات لـ JSON وحفظها
        ]);

        return response()->json([
            'message' => __('Update request submitted successfully. Pending admin approval.'),
            'pending_data' => $data
        ]);
    }

    /**
     * 3️⃣ طلب المعلم حذف حسابه (يرسل طلب للأدمن)
     * DELETE /api/teachers/request-delete
     */
    public function requestDeletion()
    {
        $user = auth()->user();
        $teacher = $user->teacher;

        if (!$teacher || $teacher->status !== 'approved') {
            return response()->json(['error' => __('You must have an approved profile to request deletion')], 403);
        }

        $teacher->update([
            'pending_action' => 'delete'
        ]);

        return response()->json([
            'message' => __('Account deletion request submitted successfully. Pending admin approval.')
        ]);
    }

    /**
     * 4️⃣ مراجعة الأدمن للطلبات (إنشاء، تعديل، حذف) والموافقة أو الرفض
     * POST /api/admin/teachers/{teacher}/review
     */
    public function reviewTeacher(Request $request, Teacher $teacher)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can review teacher requests')], 403);
        }

        $data = $request->validate([
            'action' => 'required|in:approve,reject'
        ]);

        if ($data['action'] === 'reject') {
            if ($teacher->status === 'pending') {
                $teacher->update(['status' => 'rejected']);
            } else {
                $teacher->update(['pending_action' => 'none', 'pending_data' => null]); // إلغاء طلب التعديل/الحذف
            }
            return response()->json(['message' => __('Teacher request has been rejected.')]);
        }

        // إذا كانت الموافقة (Approve)
        if ($teacher->status === 'pending') {
            $teacher->update(['status' => 'approved']);
            return response()->json(['message' => __('Teacher profile approved successfully.')]);
        }

        if ($teacher->pending_action === 'update') {
            $updateData = json_decode($teacher->pending_data, true);

            // تحديث بيانات اليوزر
            $teacher->user->update(array_filter([
                'first_name' => $updateData['first_name'] ?? null,
                'last_name' => $updateData['last_name'] ?? null,
                'phone' => $updateData['phone'] ?? null,
                'address' => $updateData['address'] ?? null,
            ]));

            // تحديث المواد
            if (isset($updateData['subject_ids'])) {
                $teacher->subjects()->sync($updateData['subject_ids']);
            }

            $teacher->update(['pending_action' => 'none', 'pending_data' => null]);
            return response()->json(['message' => __('Teacher update request approved and applied.')]);
        }

        if ($teacher->pending_action === 'delete') {
            $teacher->user->delete(); // هذا سيحذف الـ Teacher أيضاً إذا كنت تستخدم cascade
            return response()->json(['message' => __('Teacher account deleted successfully based on request.')]);
        }

        return response()->json(['message' => __('No pending requests for this teacher.')]);
    }

    /**
     * Display a listing of the resource with search and filters (Admin Only).
     */
    public function index(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can view teachers')], 403);
        }

        $data = $request->validate([
            'search' => 'nullable|string|max:255',
            'subject_id' => 'nullable|exists:subjects,id',
            'status' => 'nullable|in:pending,approved,rejected'
        ]);

        $query = Teacher::with(['user', 'subjects']);

        if ($data['search'] ?? null) {
            $search = $data['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($data['subject_id'] ?? null) {
            $query->whereHas('subjects', function ($q) use ($data) {
                $q->where('subjects.id', $data['subject_id']);
            });
        }

        if ($data['status'] ?? null) {
            $query->where('status', $data['status']);
        }

        $teachers = $query->get();

        return response()->json([
            'message' => __('Teachers retrieved successfully'),
            'data' => $teachers
        ]);
    }

    /**
     * الإضافة المباشرة من الأدمن (يتم قبول الحساب فوراً)
     */
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can create teachers')], 403);
        }

        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'password' => 'required|string|min:8',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'exists:subjects,id'
        ]);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address' => $data['address'],
            'password' => Hash::make($data['password']),
            'role' => 'teacher'
        ]);

        $teacher = Teacher::create([
            'user_id' => $user->id,
            'status' => 'approved', // الأدمن أضافه، فيُقبل فوراً
            'pending_action' => 'none'
        ]);

        if (!empty($data['subject_ids'])) {
            $teacher->subjects()->sync($data['subject_ids']);
        }

        return response()->json([
            'message' => __('Teacher created successfully by admin'),
            'data' => $teacher->load(['user', 'subjects'])
        ], 201);
    }

    /**
     * التعديل المباشر من الأدمن
     */
    public function update(Request $request, Teacher $teacher)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can update teachers directly')], 403);
        }

        $data = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|string|email|max:255|unique:users,email,' . $teacher->user_id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'exists:subjects,id',
            'status' => 'nullable|in:pending,approved,rejected'
        ]);

        $teacher->user->update(array_filter([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'password' => isset($data['password']) ? Hash::make($data['password']) : null,
        ]));

        $teacher->update(array_filter([
            'status' => $data['status'] ?? null
        ]));

        if (array_key_exists('subject_ids', $data)) {
            $teacher->subjects()->sync($data['subject_ids'] ?? []);
        }

        return response()->json([
            'message' => __('Teacher updated successfully by admin'),
            'data' => $teacher->load(['user', 'subjects'])
        ]);
    }

    /**
     * الحذف المباشر من الأدمن
     */
    public function destroy(Teacher $teacher)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Only admins can delete teachers directly')], 403);
        }

        // مسح الصورة إن وجدت
        if ($teacher->profile_image) {
            Storage::disk('public')->delete($teacher->profile_image);
        }

        $teacher->user->delete();

        return response()->json([
            'message' => __('Teacher deleted successfully by admin')
        ]);
    }

    public function show(Teacher $teacher)
    {
        if (auth()->user()->role !== 'admin' && auth()->user()->role !== 'teacher') {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        if (auth()->user()->role === 'teacher' && auth()->user()->teacher->id !== $teacher->id) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json([
            'message' => __('Teacher retrieved successfully'),
            'data' => $teacher->load(['user', 'subjects'])
        ]);
    }
}
