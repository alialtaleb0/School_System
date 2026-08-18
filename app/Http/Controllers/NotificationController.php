<?php

// ══════════════════════════════════════════════════════════════
// ملف: app/Http/Controllers/NotificationController.php
// نظام الإشعارات الداخلية (In-App Notifications) - بإذن الله تعالى
// كل دالة هنا تعمل على إشعارات المستخدم الحالي فقط (auth()->user())
// ══════════════════════════════════════════════════════════════

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * 1️⃣ قائمة إشعارات المستخدم الحالي (الأحدث أولاً) مع Pagination
     * GET /api/notifications
     * فلاتر اختيارية: ?unread=1 لعرض غير المقروءة فقط
     */
    public function index(Request $request)
    {
        $query = $request->user()->notifications();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate(20);

        return response()->json([
            'message' => __('Notifications retrieved successfully'),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'data' => $notifications,
        ]);
    }

    /**
     * 2️⃣ عدد الإشعارات غير المقروءة فقط (مفيد لعرض رقم على أيقونة الجرس)
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * 3️⃣ تعليم إشعار واحد كمقروء
     * POST /api/notifications/{id}/read
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();

        if (is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return response()->json([
            'message' => __('Notification marked as read'),
            'data' => $notification,
        ]);
    }

    /**
     * 4️⃣ تعليم كل الإشعارات كمقروءة دفعة واحدة
     * POST /api/notifications/read-all
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'message' => __('All notifications marked as read'),
        ]);
    }

    /**
     * 5️⃣ حذف إشعار واحد
     * DELETE /api/notifications/{id}
     */
    public function destroy(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->delete();

        return response()->json([
            'message' => __('Notification deleted successfully'),
        ]);
    }

    /**
     * 6️⃣ حذف كل الإشعارات (تفريغ القائمة)
     * DELETE /api/notifications
     */
    public function destroyAll(Request $request)
    {
        $request->user()->notifications()->delete();

        return response()->json([
            'message' => __('All notifications deleted successfully'),
        ]);
    }

    /**
     * 7️⃣ تسجيل/تحديث FCM token للإشعارات push (تعمل حتى لو التطبيق مغلق)
     * POST /api/fcm-token
     */
    public function registerFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $request->user()->update([
            'fcm_token' => $request->input('fcm_token'),
        ]);

        return response()->json([
            'message' => 'FCM token registered successfully',
        ]);
    }
}
