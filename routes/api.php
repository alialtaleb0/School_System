<?php


use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;


use App\Http\Controllers\AbsenceJustificationController;
use App\Http\Controllers\StudentNoteController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\HomeworkController;
use App\Http\Controllers\HomeworkSubmissionController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\StudentProgressController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeacherAttendanceController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\AcademicYearFeeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public Routes (بدون توثيق)
Route::post('register', [UserController::class, 'register']);
Route::post('login', [UserController::class, 'login']);
Route::post('verify-email', [UserController::class, 'verifyEmail']);
Route::post('resend-verification-code', [UserController::class, 'resendVerificationCode']);
Route::post('forgot-password', [UserController::class, 'forgotPassword']);
Route::post('verify-reset-code', [UserController::class, 'verifyResetCode']);
Route::post('reset-password', [UserController::class, 'resetPassword']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->post('/update-profile', [UserController::class, 'updateProfile']);

Route::middleware('auth:sanctum')->get('/test', function () {
    return auth()->user();
});

// Protected Routes (مع التوثيق)
Route::middleware('auth:sanctum')->group(function () {

    // 📌 Student endpoints (للطلاب أنفسهم)
    Route::get('/me', [StudentController::class, 'me']);
    Route::post('/students/complete-profile', [StudentController::class, 'completeProfile']);

    // 📌 Teacher endpoints (للمعلمين أنفسهم)
    //Route::post('/teachers/complete-profile', [TeacherController::class, 'completeProfile']);

    // 📌 Enrollment endpoints
    Route::post('/enroll', [StudentController::class, 'enroll']);
    Route::get('/my-enrollments', [StudentController::class, 'myEnrollments']);
    Route::delete('/enrollments/{id}', [StudentController::class, 'cancel']);

    // 📌 Attendance endpoints
   // Route::get('/attendance', [AttendanceController::class, 'index']);
   // Route::post('/attendance', [AttendanceController::class, 'store']);
    Route::post('/attendance/{id}', [AttendanceController::class, 'storeForStudent']);
    Route::get('/attendance/today', [AttendanceController::class, 'today']);
    Route::get('/attendance/{id}', [AttendanceController::class, 'studentAttendance']);
    Route::get('/attendance/{id}/today', [AttendanceController::class, 'studentAttendanceToday']);

    Route::delete('/attendance/{id}', [AttendanceController::class, 'destroy']);

    // 📌 Teacher endpoints
    Route::apiResource('/teachers', TeacherController::class)->except(['create', 'edit']);

    // 📌 Subject endpoints
    Route::apiResource('/subjects', SubjectController::class)->except(['create', 'edit']);




// أضف هذا السطر في أعلى ملف api.php مع باقي الـ use statements:
// use App\Http\Controllers\SectionController;

// ثم أضف هذا داخل مجموعة middleware('auth:sanctum'):

// 📌 Section endpoints
Route::apiResource('/sections', SectionController::class)->except(['create', 'edit']);




    // 📌 Timetable endpoints
    Route::get('/my-timetable', [TimetableController::class, 'myTimetable']);
    Route::get('/my-schedule', [TimetableController::class, 'mySchedule']);

    Route::apiResource('/timetables', TimetableController::class)->except(['create', 'edit']);
    Route::get('/timetables/{id}/schedules', [TimetableController::class, 'schedules']);
    Route::post('/timetables/{id}/schedules', [TimetableController::class, 'addSchedule']);

    // 📌 Schedule endpoints
    Route::apiResource('/schedules', ScheduleController::class)->except(['create', 'edit','store']);

    // 📌 Homework endpoints
    Route::apiResource('/homeworks', HomeworkController::class)->except(['create', 'edit']);
    Route::get('/my-homeworks', [HomeworkController::class, 'myHomeworks']);

    // 📌 Admin endpoints
    Route::get('/students', [StudentController::class, 'index']); // عرض جميع الطلاب
    Route::post('/students', [StudentController::class, 'store']); // إضافة طالب جديد
    Route::get('/students/{id}', [StudentController::class, 'show']); // عرض طالب محدد
    Route::match(['put', 'patch'], '/students/{id}', [StudentController::class, 'update']); // تعديل بيانات طالب
    Route::delete('/students/{id}', [StudentController::class, 'destroy']); // حذف طالب

    // 📌 Admin Enrollment Approval endpoints
    Route::get('/enrollments', [StudentController::class, 'listEnrollments']); // عرض جميع الطلبات
    Route::put('/enrollments/{id}/approve', [StudentController::class, 'approveEnrollment']); // الموافقة
    Route::put('/enrollments/{id}/reject', [StudentController::class, 'rejectEnrollment']); // الرفض

    // 📌 Admin Teacher Approval endpoints
  //  Route::get('/teacher-enrollments', [TeacherController::class, 'listEnrollments']); // عرض جميع طلبات المعلمين
   // Route::put('/teacher-enrollments/{id}/approve', [TeacherController::class, 'approveEnrollment']); // الموافقة
   // Route::put('/teacher-enrollments/{id}/reject', [TeacherController::class, 'rejectEnrollment']); // الرفض
});




// مسارات تتطلب تسجيل الدخول (Authenticated Users)
Route::middleware('auth:sanctum')->group(function () {

    // 1️⃣ عرض الامتحانات القادمة (ملاحظة: وضعناه في الأعلى حتى لا تظن لارافيل أن كلمة upcoming هي id)
    Route::get('exams/upcoming', [ExamController::class, 'upcomingExams']);

    // 2️⃣ جلب جميع الامتحانات
    Route::get('exams', [ExamController::class, 'index']);

    // 3️⃣ إنشاء امتحان جديد
    Route::post('exams', [ExamController::class, 'store']);

    // 4️⃣ عرض تفاصيل امتحان معين
    Route::get('exams/{exam}', [ExamController::class, 'show']);

    // 5️⃣ تعديل بيانات امتحان (يدعم PUT و PATCH)
    Route::match(['put', 'patch'], 'exams/{exam}', [ExamController::class, 'update']);

    // 6️⃣ حذف امتحان
    Route::delete('exams/{exam}', [ExamController::class, 'destroy']);

    // 7️⃣ جلب جميع المستويات الدراسية
    Route::get('levels', [LevelController::class, 'index']);

});




// تطبيق مجموعة المسارات المحمية بـ Middleware التحقق من تسجيل الدخول (مثل sanctum)
Route::middleware('auth:sanctum')->group(function () {

    // 1️⃣ جلب قائمة بجميع درجات الطلاب (تستدعي دالة index)
    // GET /api/grades
    Route::get('grades', [GradeController::class, 'index']);

    // 2️⃣ إضافة درجة جديدة لطالب مع التحقق المتقدم (تستدعي دالة store)
    // POST /api/grades
    Route::post('grades', [GradeController::class, 'store']);

    // 3️⃣ عرض تفاصيل سجل درجة معينة (تستدعي دالة show)
    // GET /api/grades/{grade}
    Route::get('grades/{grade}', [GradeController::class, 'show']);

    // 4️⃣ تعديل درجة طالب (تستدعي دالة update وتدعم كلا الطريقتين PUT و PATCH)
    // PUT/PATCH /api/grades/{grade}
    Route::match(['put', 'patch'], 'grades/{grade}', [GradeController::class, 'update']);

    // 5️⃣ حذف درجة طالب معينة بعد التحقق من الصلاحيات (تستدعي دالة destroy)
    // DELETE /api/grades/{grade}
    Route::delete('grades/{grade}', [GradeController::class, 'destroy']);

});







Route::middleware('auth:sanctum')->group(function () {

    // مسار إنشاء الأب (يُفضل حمايته بـ middleware إضافي للأدمن لاحقاً)
    Route::post('parents', [ParentController::class, 'store']);

    // مسار الأب لاستعراض علامات أبنائه
    Route::get('parent/grades', [ParentController::class, 'getChildrenGrades']);

});






Route::middleware('auth:sanctum')->group(function () {

    // 1. مسار خاص بالأب لإكمال بياناته وطلب ربط الأبناء
    Route::post('parent/complete-profile', [ParentController::class, 'completeProfile']);

    // 2. مسار خاص بالأدمن للموافقة أو الرفض على الحساب (يمرر id الأب في الرابط)
    Route::post('admin/parents/{id}/review', [ParentController::class, 'reviewProfile']);

    // 2.1. إدارة كاملة لحسابات أولياء الأمور من قِبل الأدمن (عرض/تعديل/حذف مباشر)
    Route::get('admin/parents', [ParentController::class, 'index']);
    Route::get('admin/parents/{parent}', [ParentController::class, 'show']);
    Route::match(['put', 'patch'], 'admin/parents/{parent}', [ParentController::class, 'update']);
    Route::delete('admin/parents/{parent}', [ParentController::class, 'destroy']);

    // 3. لوحة تحكم الأب (تشتغل تلقائياً فقط بعد الـ approve من الأدمن)
    Route::get('parent/children', [ParentController::class, 'myChildren']);
    Route::get('parent/grades', [ParentController::class, 'getChildrenGrades']);
    Route::get('parent/attendance', [ParentController::class, 'getChildrenAttendance']);

    // 4. متابعة البرنامج الدراسي للأبناء (يجب تعريف المسار العام قبل مسار {student})
    Route::get('parent/timetable', [TimetableController::class, 'childrenTimetables']);
    Route::get('parent/timetable/{student}', [TimetableController::class, 'childTimetable']);

});









// مسارات خاصة بالمعلم
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/teachers/complete-profile', [TeacherController::class, 'completeProfile']);
    Route::post('/teachers/request-update', [TeacherController::class, 'requestUpdate']);
    Route::delete('/teachers/request-delete', [TeacherController::class, 'requestDeletion']);
    Route::get('/teachers/{teacher}', [TeacherController::class, 'show']);
});

// مسارات خاصة بالأدمن للتحكم بالمعلمين
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::post('/teachers', [TeacherController::class, 'store']);
    Route::put('/teachers/{teacher}', [TeacherController::class, 'update']);
    Route::delete('/teachers/{teacher}', [TeacherController::class, 'destroy']);

    // مسار مراجعة طلبات المعلمين (موافقة أو رفض)
    Route::post('/teachers/{teacher}/review', [TeacherController::class, 'reviewTeacher']);
});








Route::get('/certificates/verify/{token}', [CertificateController::class, 'verify'])
    ->name('certificates.verify');


// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// أضف هذه المسارات داخل middleware auth:sanctum
// ══════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // 📌 الأدمن — توليد شهادة لطالب
    Route::post('/certificates', [CertificateController::class, 'generate']);

    // 📌 الأدمن — عرض شهادات طالب محدد
    Route::get('/certificates/student/{id}', [CertificateController::class, 'studentCertificates']);

    // 📌 عرض تفاصيل شهادة واحدة (أدمن / الطالب / وليّ الأمر)
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show']);

    // 📌 الطالب — يرى شهاداته الخاصة
    Route::get('/my-certificates', [CertificateController::class, 'myCertificates']);

    // 📌 الأب — يرى شهادات أبنائه
    Route::get('/parent/certificates', [CertificateController::class, 'childrenCertificates']);

});










// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// نظام المحادثة الفورية (Chat) بين الطالب/الأب والمدرّس - بإذن الله تعالى
// أضف use statements التالية في أعلى ملف api.php:
//
// use App\Http\Controllers\ConversationController;
// use App\Http\Controllers\MessageController;
//
// ثم أضف هذه المجموعة كاملة داخل ملف api.php (يفضل بعد المجموعات الموجودة)
// ══════════════════════════════════════════════════════════════


Route::middleware('auth:sanctum')->prefix('conversations')->group(function () {

    // 📌 قائمة محادثاتي (مرتبة حسب آخر رسالة)
    Route::get('/', [ConversationController::class, 'index']);

    // 📌 قائمة "جهات الاتصال" المسموح لي بدء محادثة معها حسب الجدول
    Route::get('/contacts', [ConversationController::class, 'contacts']);

    // 📌 بدء محادثة ثنائية جديدة (أو إرجاع الموجودة فعلاً)
    Route::post('/direct', [ConversationController::class, 'startDirect']);

    // 📌 إنشاء مجموعة جديدة (فقط المدرّس أو الأدمن) مع اختيار يدوي للأعضاء
    Route::post('/group', [ConversationController::class, 'createGroup']);

    // 📌 عرض محادثة محددة مع رسائلها (Pagination)
    Route::get('/{conversation}', [ConversationController::class, 'show']);

    // 📌 إضافة عضو جديد لمجموعة موجودة
    Route::post('/{conversation}/members', [ConversationController::class, 'addMember']);

    // 📌 مغادرة مجموعة
    Route::delete('/{conversation}/leave', [ConversationController::class, 'leave']);

    // 📌 إرسال رسالة جديدة (نص و/أو مرفقات) داخل المحادثة
    Route::post('/{conversation}/messages', [MessageController::class, 'store']);

    // 📌 تعليم جميع رسائل المحادثة كمقروءة
    Route::post('/{conversation}/read', [MessageController::class, 'markAsRead']);

    // 📌 بث حالة "يكتب الآن..." لباقي المشاركين
    Route::post('/{conversation}/typing', [MessageController::class, 'typing']);
});

// 📌 حذف رسالة محددة (فقط مرسلها أو الأدمن)
Route::middleware('auth:sanctum')->delete('/messages/{message}', [MessageController::class, 'destroy']);


// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// ميزة مقارنة تقدّم الطالب بنفسه (Progress Comparison) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // 📌 مقارنة تقدّم طالب معيّن بين فترتين زمنيتين (علامات + حضور + واجبات)
    // الصلاحية: الطالب نفسه / ولي أمره / معلمه الفعلي / الأدمن (يُفحص داخل الكنترولر عبر Policy)
    Route::get('/students/{id}/progress-comparison', [StudentProgressController::class, 'compare']);

    // 📌 الأب — يرى تقدّم جميع أبنائه دفعةً واحدة (بين فترتين)
    Route::get('/parent/children-progress', [StudentProgressController::class, 'childrenProgress']);

    // 📌 الأب — يرى تقدّم ابن واحد بالتفصيل
    Route::get('/parent/children-progress/{student}', [StudentProgressController::class, 'oneChildProgress']);

    // 📌 المعلم — يرى قائمة طلابه مع تقدّم كل واحد منهم (بين فترتين)
    Route::get('/teacher/students-progress', [StudentProgressController::class, 'myStudentsProgress']);

    // 📌 المعلم — يرى تقدّم طالب واحد بالتفصيل
    Route::get('/teacher/students-progress/{student}', [StudentProgressController::class, 'oneStudentProgress']);

});

// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// تسليم وتقييم الواجبات (Homework Submissions) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // 📌 الطالب يسلّم واجباً
    Route::post('/homeworks/{homework}/submit', [HomeworkSubmissionController::class, 'submit']);

    // 📌 المعلم/الأدمن يعرض كل تسليمات واجب معيّن
    Route::get('/homeworks/{homework}/submissions', [HomeworkSubmissionController::class, 'index']);

    // 📌 المعلم يقيّم تسليم طالب (علامة + ملاحظة)
    Route::match(['put', 'patch'], '/homework-submissions/{submission}/grade', [HomeworkSubmissionController::class, 'grade']);

});

// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// العطل الرسمية (Holidays) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // 📌 عرض كل العطل الرسمية (لأي مستخدم مسجّل، مفيد للتقويم وحساب الحضور)
    Route::get('/holidays', [HolidayController::class, 'index']);

    // 📌 إضافة عطلة رسمية جديدة (الأدمن فقط)
    Route::post('/holidays', [HolidayController::class, 'store']);

    // 📌 تعديل عطلة رسمية (الأدمن فقط)
    Route::match(['put', 'patch'], '/holidays/{holiday}', [HolidayController::class, 'update']);

    // 📌 حذف عطلة رسمية (الأدمن فقط)
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);

});// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// محفظة ولي الأمر (Wallet) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // 📌 ولي الأمر — رصيده وآخر حركاته
    Route::get('/parent/wallet', [WalletController::class, 'myWallet']);

    // 📌 ولي الأمر — سجل حركاته بالكامل (Pagination)
    Route::get('/parent/wallet/transactions', [WalletController::class, 'transactions']);
});

Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {

    // 📌 الأدمن — قائمة كل محافظ أولياء الأمور
    Route::get('/wallets', [WalletController::class, 'adminIndex']);

    // 📌 الأدمن — محفظة أب محدد مع سجل حركاتها
    Route::get('/wallets/{parentId}', [WalletController::class, 'adminShow']);

    // 📌 الأدمن — شحن رصيد يدوياً لمحفظة أب
    Route::post('/wallets/{parentId}/deposit', [WalletController::class, 'deposit']);

    // 📌 الأدمن — خصم/تعديل رصيد يدوياً (تصحيح)
    Route::post('/wallets/{parentId}/withdraw', [WalletController::class, 'withdraw']);

    // 📌 الأدمن — تحديد/تعديل رسوم سنة دراسية معينة
    Route::post('/academic-year-fees', [AcademicYearFeeController::class, 'store']);

    // 📌 الأدمن — حذف رسوم سنة دراسية (تعود مجانية)
    Route::delete('/academic-year-fees/{id}', [AcademicYearFeeController::class, 'destroy']);
});

// 📌 عرض رسوم كل السنوات الدراسية (لأي مستخدم مسجّل دخول، ليرى الرسوم قبل التسجيل)
Route::middleware('auth:sanctum')->get('/academic-year-fees', [AcademicYearFeeController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/teacher-attendance', [TeacherAttendanceController::class, 'index']);
    Route::post('/teacher-attendance', [TeacherAttendanceController::class, 'store']);

    // 📌 لوحة حضور اليوم لجميع المعلمين (الأدمن فقط)
    Route::get('/teacher-attendance/today', [TeacherAttendanceController::class, 'today']);

    // 📌 المعلم يرى سجله هو فقط (من التوكن مباشرة) - يجب أن تُعرَّف قبل {id}
    Route::get('/teacher-attendance/me', [TeacherAttendanceController::class, 'myAttendance']);
    Route::get('/teacher-attendance/me/today', [TeacherAttendanceController::class, 'myAttendanceToday']);

    // 📌 مسارات الأدمن التي تعتمد على معرّف معلم محدد
    Route::post('/teacher-attendance/{id}', [TeacherAttendanceController::class, 'storeForTeacher']);
    Route::match(['put', 'patch'], '/teacher-attendance/{id}', [TeacherAttendanceController::class, 'update']);
    Route::get('/teacher-attendance/{id}', [TeacherAttendanceController::class, 'teacherAttendance']);
    Route::get('/teacher-attendance/{id}/today', [TeacherAttendanceController::class, 'teacherAttendanceToday']);
    Route::delete('/teacher-attendance/{id}', [TeacherAttendanceController::class, 'destroy']);

});

// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// نظام الإشعارات الداخلية (In-App Notifications) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {

    // 📌 قائمة إشعارات المستخدم الحالي (Pagination) — أضف ?unread=1 لغير المقروءة فقط
    Route::get('/', [NotificationController::class, 'index']);

    // 📌 عدد الإشعارات غير المقروءة (لأيقونة الجرس)
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);

    // 📌 تعليم كل الإشعارات كمقروءة دفعة واحدة
    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);

    // 📌 تعليم إشعار واحد كمقروء
    Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);

    // 📌 حذف كل الإشعارات
    Route::delete('/', [NotificationController::class, 'destroyAll']);

    // 📌 حذف إشعار واحد
    Route::delete('/{id}', [NotificationController::class, 'destroy']);
});

// 📌 تسجيل FCM token للإشعارات push (تعمل حتى لو التطبيق مغلق)
Route::middleware('auth:sanctum')->post('/fcm-token', [NotificationController::class, 'registerFcmToken']);

// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// طلبات تبرير الغياب (Absence Justifications) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // 📌 الطالب/وليّ الأمر — تقديم طلب تبرير غياب جديد
    Route::post('/absence-justifications', [AbsenceJustificationController::class, 'store']);

    // 📌 الطالب/وليّ الأمر — عرض طلباته الخاصة
    Route::get('/my-absence-justifications', [AbsenceJustificationController::class, 'myRequests']);

    // 📌 المعلم/الأدمن — عرض الطلبات الواردة (مقيّدة بطلاب المعلم فعلياً)
    Route::get('/absence-justifications', [AbsenceJustificationController::class, 'index']);

    // 📌 عرض تفاصيل طلب واحد (صاحبه / معلمه / الأدمن)
    Route::get('/absence-justifications/{id}', [AbsenceJustificationController::class, 'show']);

    // 📌 المعلم/الأدمن — قبول أو رفض الطلب
    Route::match(['put', 'patch'], '/absence-justifications/{id}/approve', [AbsenceJustificationController::class, 'approve']);
    Route::match(['put', 'patch'], '/absence-justifications/{id}/reject', [AbsenceJustificationController::class, 'reject']);

    // 📌 حذف طلب (صاحبه طالما لا يزال pending، أو الأدمن في أي وقت)
    Route::delete('/absence-justifications/{id}', [AbsenceJustificationController::class, 'destroy']);
});

// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// ملاحظات المعلمين عن الطلاب والرد عليها (Student Notes) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // 📌 المعلم — كتابة ملاحظة جديدة عن أحد طلابه الفعليين
    Route::post('/student-notes', [StudentNoteController::class, 'store']);

    // 📌 عرض الملاحظات (مقيّدة حسب الدور: معلم/وليّ أمر/طالب/أدمن)
    Route::get('/student-notes', [StudentNoteController::class, 'index']);

    // 📌 عرض ملاحظة واحدة مع كامل خيط الردود عليها
    Route::get('/student-notes/{id}', [StudentNoteController::class, 'show']);

    // 📌 الرد على ملاحظة
    Route::post('/student-notes/{id}/replies', [StudentNoteController::class, 'storeReply']);

    // 📌 حذف ملاحظة (كاتبها فقط أو الأدمن)
    Route::delete('/student-notes/{id}', [StudentNoteController::class, 'destroy']);
});

// ══════════════════════════════════════════════════════════════
// ملف: routes/api.php
// تقارير الحضور والغياب (Attendance Reports) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // 📌 الأدمن — تقرير حضور وغياب شامل (فلترة اختيارية: from, to, section_id, level_id)
    Route::get('/reports/attendance', [AttendanceReportController::class, 'summary']);
});
