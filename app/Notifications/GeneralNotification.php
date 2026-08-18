<?php

// ══════════════════════════════════════════════════════════════
// ملف: app/Notifications/GeneralNotification.php
// نظام الإشعارات الداخلية (In-App Notifications) - بإذن الله تعالى
//
// كلاس إشعار عام واحد يُستخدم لكل أنواع الإشعارات في النظام (رسالة جديدة،
// واجب جديد، درجة جديدة، حضور، محفظة، ...)، بدلاً من إنشاء كلاس منفصل
// لكل نوع. يميّز نوع الإشعار حقل "type" النصي المُمرَّر عند الإنشاء.
//
// القنوات المستخدمة:
// - database  : يُخزَّن الإشعار في جدول notifications (لعرضه لاحقاً/جرس الإشعارات)
// - broadcast : يُبث فورياً عبر Laravel Reverb الموجود لديكم على قناة خاصة
//               بكل مستخدم (App.Models.User.{id})، بدون أي خدمة خارجية
//               (لا حاجة لـ Firebase / Google) - راجع routes/channels.php
// ══════════════════════════════════════════════════════════════

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification
{
    /**
     * @param  string  $title   عنوان الإشعار (يُعرض في القائمة/الجرس)
     * @param  string  $body    نص الإشعار التفصيلي
     * @param  string  $type    نوع الإشعار، مثال: "new_message", "new_homework",
     *                          "new_grade", "attendance_recorded", "homework_graded",
     *                          "enrollment_approved", "enrollment_rejected",
     *                          "parent_account_approved", "parent_account_rejected",
     *                          "wallet_deposit", "wallet_withdrawal", "certificate_issued"
     * @param  array   $data    بيانات إضافية حرة (مثل conversation_id, homework_id ...)
     *                          تُستخدم في الواجهة الأمامية للانتقال المباشر لمصدر الإشعار
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $type,
        public array $data = []
    ) {
    }

    /**
     * القنوات التي يُرسَل عبرها هذا الإشعار.
     */
    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * الشكل المخزَّن في جدول notifications (قناة database).
     */
    public function toDatabase($notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
            'data' => $this->data,
        ];
    }

    /**
     * الشكل المُرسَل فورياً عبر Reverb للعميل المتصل حالياً (قناة broadcast).
     * يستمع إليها الفرونت إند عبر Laravel Echo:
     *   Echo.private(`App.Models.User.${userId}`).notification((notification) => { ... })
     */
    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
            'data' => $this->data,
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * اسم الحدث كما سيصل للعميل (اختياري، لتسهيل الاستماع من الفرونت إند
     * بأي طريقة أخرى غير Echo.notification()، مثل الاستماع اليدوي للقناة).
     */
    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
