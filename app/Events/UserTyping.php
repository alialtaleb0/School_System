<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يُبث فوراً (بدون قائمة انتظار، ShouldBroadcastNow) عند بدء/توقف المستخدم
 * عن الكتابة في محادثة معيّنة، لإظهار مؤشر "يكتب الآن..." في الواجهة.
 */
class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $conversationId;
    public User $user;
    public bool $isTyping;

    public function __construct(int $conversationId, User $user, bool $isTyping)
    {
        $this->conversationId = $conversationId;
        $this->user = $user;
        $this->isTyping = $isTyping;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->user->id,
            'name' => $this->user->name,
            'is_typing' => $this->isTyping,
        ];
    }

    /**
     * لا تُبث هذه الرسالة إلى المرسل نفسه، فقط لباقي المشاركين في المحادثة
     */
    public function broadcastWhen(): bool
    {
        return true;
    }
}
