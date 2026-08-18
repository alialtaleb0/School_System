<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Grade;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CertificateController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    // ════════════════════════════════════════════════════════════
    // 1️⃣  توليد الشهادة (الأدمن فقط)
    //     POST /api/certificates
    // ════════════════════════════════════════════════════════════

    public function generate(Request $request)
    {
        // صلاحية الأدمن فقط
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        $data = $request->validate([
            'student_id'    => 'required|exists:students,id',
            'academic_year' => 'required|string',   // يقبل "2026" أو "2025-2026"
        ]);

        $student = Student::findOrFail($data['student_id']);

        // ── استخراج سنة النهاية والبداية بغض النظر عن التنسيق ──
        // يدعم: "2026" أو "2025-2026"
        if (str_contains($data['academic_year'], '-')) {
            [$startYear, $endYear] = explode('-', $data['academic_year']);
        } else {
            $endYear   = $data['academic_year'];          // "2026"
            $startYear = (string)((int)$endYear - 1);     // "2025"
        }

        // ── جلب آخر تسجيل معتمد للطالب بمطابقة سنة النهاية ────
        // يطابق سواء كان محفوظاً "2026" أو "2025-2026"
        $enrollment = StudentEnrollment::where('student_id', $student->id)
            ->where(function ($q) use ($endYear, $startYear) {
                $q->where('academic_year', $endYear)
                  ->orWhere('academic_year', $startYear . '-' . $endYear);
            })
            ->where('status', 'approved')
            ->with(['level', 'section'])
            ->latest()
            ->first();

        if (! $enrollment) {
            return response()->json([
                'error' => __('No approved enrollment found for this student in the specified year')
            ], 422);
        }

        // ── حساب المعدل النهائي تلقائياً من جدول grades ───────
        $gradesQuery = Grade::where('student_id', $student->id)
            ->whereHas('exam', function ($q) use ($startYear, $endYear) {
                $q->whereBetween('start_time', [
                    $startYear . '-09-01 00:00:00',
                    $endYear   . '-06-30 23:59:59',
                ]);
            });

        $gradesCount = $gradesQuery->count();

        if ($gradesCount === 0) {
            return response()->json([
                'error' => __('No grades recorded for this student in this year')
            ], 422);
        }

        $finalGrade = round($gradesQuery->avg('mark'), 2);

        // ── تحديد الحالة بناءً على المعدل ──────────────────────
        $status = match (true) {
            $finalGrade >= 90 => 'متفوق',
            $finalGrade >= 50 => 'ناجح',
            default           => 'راسب',
        };

        // ── توليد توكن فريد وبناء Signed URL ───────────────────
        $token = Str::uuid()->toString();

        $verifyUrl = \URL::signedRoute(
            'certificates.verify',
            ['token' => $token],
            now()->addYears(10)   // صالح لـ 10 سنوات
        );

        // ── توليد صورة QR وحفظها ────────────────────────────────
        $qrPath = 'certificates/qr/' . $token . '.svg';
        \Storage::disk('public')->put(
            $qrPath,
            QrCode::format('svg')->size(300)->errorCorrection('H')->generate($verifyUrl)
        );

        // ── حفظ الشهادة في قاعدة البيانات ──────────────────────
        $certificate = Certificate::create([
            'student_id'    => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_year' => $data['academic_year'],
            'level'         => $enrollment->level->name,
            'section'       => $enrollment->section->name ?? null,
            'final_grade'   => $finalGrade,
            'status'        => $status,
            'token'         => $token,
            'qr_code_path'  => $qrPath,
            'verify_url'    => $verifyUrl,
        ]);

        $this->notifications->certificateIssued($certificate);

        return response()->json([
            'message'     => __('Certificate generated successfully'),
            'verify_url'  => $verifyUrl,
            'qr_code_url' => $certificate->qr_code_url,
            'data'        => $this->payload($certificate),
        ], 201);
    }

    // ════════════════════════════════════════════════════════════
    // 2️⃣  التحقق من الشهادة (عام — بدون تسجيل دخول)
    //     GET /certificates/verify/{token}?signature=...
    // ════════════════════════════════════════════════════════════

    public function verify(Request $request, string $token)
    {
        // التحقق من صحة التوقيع الرقمي
        if (! $request->hasValidSignature()) {
            return response()->json([
                'valid'   => false,
                'message' => __('The link is invalid or has expired'),
            ], 403);
        }

        $certificate = Certificate::where('token', $token)
            ->with('student.user')
            ->first();

        if (! $certificate) {
            return response()->json([
                'valid'   => false,
                'message' => __('This certificate was not found'),
            ], 404);
        }

        $student = $certificate->student;

        return response()->json([
            'valid'   => true,
            'message' => __('✅ This certificate is authentic and verified'),
            'data'    => [
                'student_name'   => $student->user->first_name . ' ' . $student->user->last_name,
                'student_number' => $student->student_number,
                'academic_year'  => $certificate->academic_year,
                'level'          => $certificate->level,
                'section'        => $certificate->section,
                'final_grade'    => $certificate->final_grade,
                'status'         => $certificate->status,
                'issued_at'      => $certificate->created_at->format('Y-m-d'),
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // 3️⃣  الطالب يرى شهاداته الخاصة
    //     GET /api/my-certificates
    // ════════════════════════════════════════════════════════════

    public function myCertificates()
    {
        $user = auth()->user();

        if ($user->role !== 'student') {
            return response()->json(['error' => __('This endpoint is for students only')], 403);
        }

        $student = $user->student;

        if (! $student) {
            return response()->json(['error' => __('Your student profile was not found')], 404);
        }

        $certificates = Certificate::where('student_id', $student->id)
            ->latest()
            ->get()
            ->map(fn ($cert) => $this->payload($cert));

        return response()->json([
            'message' => __('Your academic certificates'),
            'data'    => $certificates,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // 4️⃣  الأب يرى شهادات أبنائه
    //     GET /api/parent/certificates
    // ════════════════════════════════════════════════════════════

    public function childrenCertificates()
    {
        $user = auth()->user();

        if ($user->role !== 'parent') {
            return response()->json(['error' => __('This endpoint is for parents only')], 403);
        }

        $parent = $user->parent;

        if (! $parent || $parent->status !== 'approved') {
            return response()->json([
                'error' => __('Your account has not been approved by the administration yet')
            ], 403);
        }

        // جلب جميع الأبناء المرتبطين بهذا الأب
        $children = Student::where('parent_id', $parent->id)
            ->with(['user', 'certificates' => function ($q) {
                $q->latest();
            }])
            ->get();

        if ($children->isEmpty()) {
            return response()->json([
                'message' => __('No children linked to your account'),
                'data'    => [],
            ]);
        }

        $result = $children->map(function ($child) {
            return [
                'student_name'   => $child->user->first_name . ' ' . $child->user->last_name,
                'student_number' => $child->student_number,
                'certificates'   => $child->certificates->map(fn ($cert) => $this->payload($cert)),
            ];
        });

        return response()->json([
            'message' => __('Academic certificates of your children'),
            'data'    => $result,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // 5️⃣  الأدمن يرى شهادات طالب محدد
    //     GET /api/certificates/student/{id}
    // ════════════════════════════════════════════════════════════

    public function studentCertificates($id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        $student = Student::with('user')->findOrFail($id);

        $certificates = Certificate::where('student_id', $id)
            ->latest()
            ->get()
            ->map(fn ($cert) => $this->payload($cert));

        return response()->json([
            'student' => $student->user->first_name . ' ' . $student->user->last_name,
            'data'    => $certificates,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // 6️⃣  عرض تفاصيل شهادة واحدة (أدمن / الطالب صاحبها / وليّ أمره)
    //     GET /api/certificates/{certificate}
    // ════════════════════════════════════════════════════════════

    public function show($id)
    {
        $certificate = Certificate::with('student.user')->find($id);

        if (! $certificate) {
            return response()->json(['error' => __('Certificate not found')], 404);
        }

        $user = auth()->user();

        $isOwner = false;

        if ($user->role === 'student') {
            $isOwner = $user->student && $user->student->id === $certificate->student_id;
        } elseif ($user->role === 'parent') {
            $parent = $user->parent;
            $isOwner = $parent && $certificate->student->parent_id === $parent->id;
        } elseif ($user->role === 'admin') {
            $isOwner = true;
        }

        if (! $isOwner) {
            return response()->json(['error' => __('Unauthorized')], 403);
        }

        return response()->json([
            'message' => __('Certificate details'),
            'data'    => $this->payload($certificate),
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // 7️⃣  تحويل الشهادة إلى البيانات الغنية (تُستخدم في كل الردود)
    // ════════════════════════════════════════════════════════════

    private function payload(Certificate $cert): array
    {
        $student = $cert->student;

        return [
            'id'             => $cert->id,
            'student_id'     => $cert->student_id,
            'student_name'   => $student?->user
                ? $student->user->first_name . ' ' . $student->user->last_name
                : null,
            'student_number' => $student?->student_number,
            'academic_year'  => $cert->academic_year,
            'level'          => $cert->level,
            'section'        => $cert->section,
            'final_grade'    => (float) $cert->final_grade,
            'status'         => $cert->status,
            'issued_at'      => $cert->created_at?->format('Y-m-d'),
            'token'          => $cert->token,
            'verify_url'     => $cert->verify_url,
            'qr_code_url'    => $cert->qr_code_url,
        ];
    }
}
