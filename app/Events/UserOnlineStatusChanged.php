<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يُبث عند تغيّر حالة اتصال المستخدم (Online/Offline).
 * يُستخدم عادة Presence Channel (channels.php) لمعرفة من هو متصل فعلياً
 * في الوقت الحقيقي، لكن هذا الـ Event مفيد أيضاً لإشعار من غادر للتو
 * (مثلاً بعد آخر heartbeat) حتى يُحدّث العميل القائمة فوراً.
 */
class UserOnlineStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public bool $isOnline;

    public function __construct(User $user, bool $isOnline)
    {
        $this->user = $user;
        $this->isOnline = $isOnline;
    }

    /**
     * قناة عامة (Presence) مشتركة لكل مستخدمي النظام المصادَقين، يستخدمها العميل
     * لمعرفة من هو متصل الآن من بين المتحدثين معه.
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('presence.users'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.status-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'is_online' => $this->isOnline,
            'last_seen_at' => $this->user->last_seen_at?->toIso8601String(),
        ];
    }
}
