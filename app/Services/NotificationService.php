<?php

// ══════════════════════════════════════════════════════════════
// ملف: app/Services/NotificationService.php
// نظام الإشعارات الداخلية (In-App Notifications) - بإذن الله تعالى
//
// خدمة مركزية واحدة تجمع منطق إرسال كل إشعارات النظام، بدلاً من تكرار
// نفس الكود (بناء النص + استدعاء notify()) داخل كل كنترولر على حدة.
// كل دالة هنا مسؤولة عن حدث واحد فقط: من يُشعَر، وبأي نص، وبأي بيانات.
// ══════════════════════════════════════════════════════════════

namespace App\Services;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Conversation;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Message;
use App\Models\ParentModel;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FcmService;
use Illuminate\Support\Collection;

class NotificationService
{
    protected FcmService $fcm;

    public function __construct(FcmService $fcm)
    {
        $this->fcm = $fcm;
    }

    /**
     * الدالة الأساسية: إرسال إشعار لمستخدم واحد.
     * تتجاهل بصمت أي $user فارغ (null) حتى لا تحتاج كل الكنترولرات للتحقق يدوياً.
     * تُرسل الإشعار محلياً (database + broadcast) و عبر FCM Push لإظهاره حتى لو التطبيق مغلق.
     */
    public function send(?User $user, string $title, string $body, string $type, array $data = []): void
    {
        if (! $user) {
            return;
        }

        $user->notify(new \App\Notifications\GeneralNotification($title, $body, $type, $data));

        $this->fcm->sendToUser($user, $title, $body, $data);
    }

    /**
     * إرسال نفس الإشعار لعدة مستخدمين دفعة واحدة.
     *
     * @param  iterable<User|null>  $users
     */
    public function sendToMany(iterable $users, string $title, string $body, string $type, array $data = []): void
    {
        foreach ($users as $user) {
            $this->send($user, $title, $body, $type, $data);
        }
    }

    // ────────────────────────────────────────────────────────────
    // 1️⃣ رسالة جديدة في محادثة
    // ────────────────────────────────────────────────────────────

    /**
     * تُستدعى بعد إرسال رسالة جديدة، لإشعار كل المشاركين النشطين في المحادثة
     * عدا المرسل نفسه (الذي يرى رسالته مباشرة على شاشته).
     */
    public function newMessage(Message $message, Conversation $conversation): void
    {
        $sender = $message->sender ?? $message->user;

        $recipients = $conversation->activeParticipants()
            ->where('users.id', '!=', $message->user_id)
            ->get();

        $preview = $message->body
            ? \Illuminate\Support\Str::limit($message->body, 60)
            : 'أرسل مرفقاً';

        $this->sendToMany(
            $recipients,
            title: 'رسالة جديدة من ' . ($sender->name ?? 'مستخدم'),
            body: $preview,
            type: 'new_message',
            data: [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 2️⃣ واجب جديد
    // ────────────────────────────────────────────────────────────

    /**
     * تُستدعى بعد إنشاء واجب جديد، لإشعار كل طلاب الشعبة/المستوى المعنيّين.
     *
     * @param  Collection<int, \App\Models\Student>  $students
     */
    public function newHomework(Homework $homework, Collection $students): void
    {
        $recipients = $students->map(fn ($student) => $student->user)->filter();

        $this->sendToMany(
            $recipients,
            title: 'واجب جديد: ' . $homework->title,
            body: 'أضاف المعلم واجباً جديداً في مادة ' . ($homework->subject->name ?? ''),
            type: 'new_homework',
            data: ['homework_id' => $homework->id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 3️⃣ درجة جديدة
    // ────────────────────────────────────────────────────────────

    /**
     * تُستدعى بعد إضافة درجة جديدة، لإشعار الطالب نفسه ووليّ أمره معاً.
     */
    public function newGrade(Grade $grade): void
    {
        $grade->loadMissing(['student.user', 'student.parent.user', 'exam.subject']);

        $subjectName = $grade->exam->subject->name ?? '';
        $body = "حصل الطالب على درجة {$grade->mark} في مادة {$subjectName}";

        $this->send(
            $grade->student->user ?? null,
            title: 'درجة جديدة',
            body: $body,
            type: 'new_grade',
            data: ['grade_id' => $grade->id],
        );

        $this->send(
            $grade->student->parent->user ?? null,
            title: 'درجة جديدة لابنك/ابنتك',
            body: $body,
            type: 'new_grade',
            data: ['grade_id' => $grade->id, 'student_id' => $grade->student_id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 4️⃣ تسجيل حضور
    // ────────────────────────────────────────────────────────────

    /**
     * تُستدعى بعد تسجيل حضور طالب، لإشعار وليّ الأمر (والطالب نفسه إن رغبتم).
     */
    public function attendanceRecorded(Attendance $attendance): void
    {
        $attendance->loadMissing(['student.user', 'student.parent.user']);

        $body = 'تم تسجيل حضور الطالب بتاريخ ' . $attendance->date;

        $this->send(
            $attendance->student->parent->user ?? null,
            title: 'تسجيل حضور',
            body: $body,
            type: 'attendance_recorded',
            data: ['attendance_id' => $attendance->id, 'student_id' => $attendance->student_id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 5️⃣ تقييم تسليم واجب
    // ────────────────────────────────────────────────────────────

    public function homeworkGraded(HomeworkSubmission $submission): void
    {
        $submission->loadMissing(['student.user', 'homework']);

        $this->send(
            $submission->student->user ?? null,
            title: 'تم تقييم واجبك',
            body: "حصلت على علامة {$submission->mark} في واجب: {$submission->homework->title}",
            type: 'homework_graded',
            data: ['submission_id' => $submission->id, 'homework_id' => $submission->homework_id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 6️⃣ قبول/رفض طلب التحاق
    // ────────────────────────────────────────────────────────────

    public function enrollmentStatusChanged(StudentEnrollment $enrollment, string $status): void
    {
        $enrollment->loadMissing('student.user');

        $isApproved = $status === 'approved';

        $this->send(
            $enrollment->student->user ?? null,
            title: $isApproved ? 'تم قبول طلب التحاقك' : 'تم رفض طلب التحاقك',
            body: $isApproved
                ? 'تهانينا! تم قبول طلب التحاقك للعام الدراسي ' . $enrollment->academic_year
                : 'نأسف، تم رفض طلب التحاقك للعام الدراسي ' . $enrollment->academic_year,
            type: $isApproved ? 'enrollment_approved' : 'enrollment_rejected',
            data: ['enrollment_id' => $enrollment->id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 7️⃣ مراجعة حساب ولي الأمر
    // ────────────────────────────────────────────────────────────

    public function parentAccountReviewed(ParentModel $parent, string $status): void
    {
        $parent->loadMissing('user');

        $isApproved = $status === 'approved';

        $this->send(
            $parent->user ?? null,
            title: $isApproved ? 'تم قبول حسابك' : 'تم رفض حسابك',
            body: $isApproved
                ? 'تم قبول حسابك وربط أبنائك بنجاح. يمكنك الآن استخدام كل ميزات التطبيق.'
                : 'نأسف، تم رفض طلب حسابك. يرجى التواصل مع الإدارة لمزيد من التفاصيل.',
            type: $isApproved ? 'parent_account_approved' : 'parent_account_rejected',
            data: ['parent_id' => $parent->id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 8️⃣ حركة محفظة (شحن/خصم)
    // ────────────────────────────────────────────────────────────

    public function walletTransaction(ParentModel $parent, WalletTransaction $transaction): void
    {
        $parent->loadMissing('user');

        $isDeposit = in_array($transaction->type, ['deposit', 'refund'], true);

        $this->send(
            $parent->user ?? null,
            title: $isDeposit ? 'تم شحن محفظتك' : 'تم خصم من محفظتك',
            body: $isDeposit
                ? "تم إضافة {$transaction->amount} إلى رصيدك. الرصيد الحالي: {$transaction->balance_after}"
                : "تم خصم {$transaction->amount} من رصيدك. الرصيد الحالي: {$transaction->balance_after}",
            type: $isDeposit ? 'wallet_deposit' : 'wallet_withdrawal',
            data: ['transaction_id' => $transaction->id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 9️⃣ إصدار شهادة جديدة
    // ────────────────────────────────────────────────────────────

    public function certificateIssued(Certificate $certificate): void
    {
        $certificate->loadMissing(['student.user', 'student.parent.user']);

        $body = 'تم إصدار شهادة العام الدراسي ' . $certificate->academic_year;

        $this->send(
            $certificate->student->user ?? null,
            title: 'شهادة جديدة',
            body: $body,
            type: 'certificate_issued',
            data: ['certificate_id' => $certificate->id],
        );

        $this->send(
            $certificate->student->parent->user ?? null,
            title: 'شهادة جديدة لابنك/ابنتك',
            body: $body,
            type: 'certificate_issued',
            data: ['certificate_id' => $certificate->id, 'student_id' => $certificate->student_id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 🔟 طلب التحاق جديد (يصل للأدمن)
    // ────────────────────────────────────────────────────────────

    /**
     * تُستدعى فور تقديم طالب لطلب التحاق جديد (حالة pending)، لإشعار كل
     * حسابات الأدمن بوجود طلب جديد بانتظار المراجعة.
     */
    public function enrollmentRequested(StudentEnrollment $enrollment): void
    {
        $enrollment->loadMissing('student.user');

        $admins = User::where('role', 'admin')->get();

        $studentName = $enrollment->student->user->name ?? 'طالب';

        $this->sendToMany(
            $admins,
            title: 'طلب التحاق جديد',
            body: "تقدّم الطالب {$studentName} بطلب التحاق جديد للسنة الدراسية {$enrollment->academic_year}، بانتظار المراجعة.",
            type: 'enrollment_requested',
            data: ['enrollment_id' => $enrollment->id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 1️⃣1️⃣ امتحان جديد
    // ────────────────────────────────────────────────────────────

    /**
     * تُستدعى بعد إنشاء امتحان جديد، لإشعار طلاب المعلم الذين ينطبق عليهم
     * هذا الامتحان (حسب جدوله الفعلي).
     *
     * @param  Collection<int, \App\Models\Student>  $students
     */
    public function newExam(Exam $exam, Collection $students): void
    {
        $exam->loadMissing('subject');
        $recipients = $students->map(fn ($student) => $student->user)->filter();

        $this->sendToMany(
            $recipients,
            title: 'امتحان جديد: ' . $exam->name,
            body: 'تمت إضافة امتحان جديد في مادة ' . ($exam->subject->name ?? '') . ' بتاريخ ' . $exam->start_time,
            type: 'new_exam',
            data: ['exam_id' => $exam->id],
        );
    }

    // ────────────────────────────────────────────────────────────
    // 1️⃣2️⃣ تعديل الجدول الدراسي (إضافة/تعديل/حذف حصة، أو تعديل بيانات الجدول)
    // ────────────────────────────────────────────────────────────

    /**
     * تُستدعى عند أي تغيير في الجدول الدراسي، لإشعار كل طلاب الشعبة المعنيّة
     * والمعلم المعنيّ (إن وُجد) بنص الرسالة المُمرَّر.
     */
    public function timetableChanged(?Section $section, ?Teacher $teacher, string $message, array $data = []): void
    {
        if ($section) {
            $students = StudentEnrollment::where('status', 'approved')
                ->where('section_id', $section->id)
                ->with('student.user')
                ->get()
                ->pluck('student.user')
                ->filter();

            $this->sendToMany(
                $students,
                title: 'تحديث الجدول الدراسي',
                body: $message,
                type: 'timetable_updated',
                data: $data,
            );
        }

        if ($teacher) {
            $teacher->loadMissing('user');

            $this->send(
                $teacher->user,
                title: 'تحديث في جدولك',
                body: $message,
                type: 'timetable_updated',
                data: $data,
            );
        }
    }
}
