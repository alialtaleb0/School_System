<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يُبث فوراً (Real-time) عبر Laravel Reverb إلى جميع المشاركين في المحادثة
 * عند إرسال رسالة جديدة، عدا المرسل نفسه (broadcastToOthers في الكنترولر).
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message->load(['sender', 'attachments']);
    }

    /**
     * القناة الخاصة بالمحادثة. كل محادثة لها قناة خاصة (Private Channel) مستقلة
     * بصلاحية وصول محمية عبر routes/channels.php
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    /**
     * اسم الحدث كما سيستقبله العميل (Frontend) عبر Echo/Reverb
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'user_id' => $this->message->user_id,
                'body' => $this->message->body,
                'type' => $this->message->type,
                'created_at' => $this->message->created_at?->toIso8601String(),
                'sender' => $this->message->sender ? [
                    'id' => $this->message->sender->id,
                    'name' => $this->message->sender->name,
                    'role' => $this->message->sender->role,
                ] : null,
                'attachments' => $this->message->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'file_name' => $a->file_name,
                    'file_type' => $a->file_type,
                    'mime_type' => $a->mime_type,
                    'file_size' => $a->file_size,
                    'url' => $a->url,
                ]),
            ],
        ];
    }
}
