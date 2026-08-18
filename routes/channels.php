<?php

// ══════════════════════════════════════════════════════════════
// ملف: routes/channels.php
// صلاحيات قنوات البث الفوري (Laravel Reverb) لنظام المحادثة - بإذن الله تعالى
// إن كان الملف موجوداً مسبقاً وفيه قنوات أخرى، فقط أضف الكود أدناه في نفس الملف.
// ══════════════════════════════════════════════════════════════

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

/**
 * قناة خاصة (Private) لكل محادثة على حدة.
 * لا يُسمح بالاشتراك فيها إلا لمن هو مشارك نشط فعلاً في تلك المحادثة،
 * (نفس فحص ConversationPolicy::canAccessConversation تماماً)، حتى لا يستطيع
 * أي مستخدم التطفل على رسائل محادثة لا ينتمي إليها عبر WebSocket.
 */
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);

    if (! $conversation) {
        return false;
    }

    return $conversation->activeParticipants()
        ->where('users.id', $user->id)
        ->exists();
});

/**
 * قناة وجود (Presence) عامة لكل المستخدمين المصادَقين في النظام، تُستخدم لمعرفة
 * من هو "متصل الآن" بشكل فوري عبر Reverb (Presence Channels تُرجع تلقائياً
 * قائمة كل من هو متصل حالياً عند الانضمام إليها من العميل).
 */
Broadcast::channel('presence.users', function ($user) {
    // أي مستخدم مصادَق (auth:sanctum) يحق له الانضمام لقناة الحضور العامة
    return [
        'id' => $user->id,
        'name' => $user->name,
        'role' => $user->role,
    ];
});

// ══════════════════════════════════════════════════════════════
// نظام الإشعارات الداخلية (In-App Notifications) - بإذن الله تعالى
// ══════════════════════════════════════════════════════════════

/**
 * قناة خاصة بكل مستخدم لاستقبال إشعاراته الخاصة فوراً عبر Reverb.
 * هذا هو اسم القناة الافتراضي الذي يستخدمه نظام Notifications في Laravel
 * تلقائياً (App.Models.User.{id})، لذا لا حاجة لأي إعداد إضافي في الفرونت إند
 * سوى الاستماع عبر: Echo.private(`App.Models.User.${userId}`).notification(...)
 * لا يُسمح بالاشتراك فيها إلا لصاحب الحساب نفسه.
 */
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
