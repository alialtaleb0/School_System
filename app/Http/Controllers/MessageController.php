<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Policies\ConversationPolicy;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    protected ConversationPolicy $policy;
    protected NotificationService $notifications;

    public function __construct(ConversationPolicy $policy, NotificationService $notifications)
    {
        $this->policy = $policy;
        $this->notifications = $notifications;
    }

    /**
     * 1️⃣ إرسال رسالة جديدة (نص و/أو مرفقات) داخل محادثة معيّنة
     * POST /api/conversations/{id}/messages
     * body (multipart/form-data): { "body": "...", "attachments[]": [file, file] }
     */
    public function store(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        if (! $this->policy->canAccessConversation($user, $conversation)) {
            return response()->json(['error' => __('Unauthorized. You are not a participant of this conversation.')], 403);
        }

        $data = $request->validate([
            'body' => 'nullable|string|max:5000',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,txt',
        ]);

        if (empty($data['body']) && empty($request->file('attachments'))) {
            return response()->json(['error' => __('A message must contain text or at least one attachment.')], 422);
        }

        $message = DB::transaction(function () use ($request, $conversation, $user, $data) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => $data['body'] ?? null,
                'type' => $request->hasFile('attachments') ? 'attachment' : 'text',
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('conversations/' . $conversation->id, 'public');
                    $isImage = Str::startsWith($file->getMimeType(), 'image/');

                    MessageAttachment::create([
                        'message_id' => $message->id,
                        'file_path' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_type' => $isImage ? 'image' : 'file',
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        $message->load(['sender', 'attachments']);

        // البث الفوري عبر Laravel Reverb لجميع المشاركين الآخرين في المحادثة
        broadcast(new MessageSent($message))->toOthers();

        // إشعار داخلي لجميع المشاركين الآخرين في المحادثة (جرس الإشعارات)
        $this->notifications->newMessage($message, $conversation);

        return response()->json([
            'message' => __('Message sent successfully.'),
            'data' => $this->formatMessage($message),
        ], 201);
    }

    /**
     * 2️⃣ تعليم جميع رسائل المحادثة كمقروءة من قبل المستخدم الحالي
     * POST /api/conversations/{id}/read
     */
    public function markAsRead(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        if (! $this->policy->canAccessConversation($user, $conversation)) {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $conversation->participants()->updateExistingPivot($user->id, [
            'last_read_at' => now(),
        ]);

        return response()->json(['message' => __('Marked as read.')]);
    }

    /**
     * 3️⃣ بث حالة "يكتب الآن..." (Typing Indicator) لباقي المشاركين
     * POST /api/conversations/{id}/typing
     * body: { "is_typing": true }
     */
    public function typing(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        if (! $this->policy->canAccessConversation($user, $conversation)) {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        $data = $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        broadcast(new UserTyping($conversation->id, $user, $data['is_typing']))->toOthers();

        return response()->json(['message' => __('ok')]);
    }

    /**
     * 4️⃣ حذف رسالة (Soft Delete)، فقط مرسلها الأصلي أو الأدمن
     * DELETE /api/messages/{id}
     */
    public function destroy(Request $request, Message $message)
    {
        $user = $request->user();

        if ($user->id !== $message->user_id && $user->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. You can only delete your own messages.')], 403);
        }

        $message->delete();

        return response()->json(['message' => __('Message deleted successfully.')]);
    }

    protected function formatMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'body' => $message->body,
            'type' => $message->type,
            'created_at' => $message->created_at?->toIso8601String(),
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'role' => $message->sender->role,
            ] : null,
            'attachments' => $message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'file_name' => $a->file_name,
                'file_type' => $a->file_type,
                'mime_type' => $a->mime_type,
                'file_size' => $a->file_size,
                'url' => $a->url,
            ]),
        ];
    }
}
