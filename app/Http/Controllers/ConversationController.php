<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Policies\ConversationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    protected ConversationPolicy $policy;

    public function __construct(ConversationPolicy $policy)
    {
        $this->policy = $policy;
    }

    /**
     * 1️⃣ قائمة محادثاتي (مرتبة حسب آخر رسالة)
     * GET /api/conversations
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $conversations = $user->conversations()
            ->wherePivotNull('left_at')
            ->with([
                'creator',
                'activeParticipants',
                'messages' => fn ($q) => $q->latest()->limit(1)->with('sender'),
            ])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(function (Conversation $conversation) use ($user) {
                return $this->formatConversation($conversation, $user);
            });

        return response()->json(['data' => $conversations]);
    }

    /**
     * 2️⃣ عرض محادثة محددة مع رسائلها (Pagination)
     * GET /api/conversations/{id}
     */
    public function show(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        if (! $this->policy->canAccessConversation($user, $conversation)) {
            return response()->json(['error' => __('Unauthorized. You are not a participant of this conversation.')], 403);
        }

        $messages = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->latest()
            ->paginate(30);

        $conversation->load([
            'activeParticipants',
            'messages' => fn ($q) => $q->latest()->limit(1)->with('sender'),
        ]);

        return response()->json([
            'data' => [
                'conversation' => $this->formatConversation($conversation, $user),
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * 3️⃣ بدء محادثة ثنائية جديدة مع مستخدم محدد، أو إرجاع المحادثة الموجودة
     * فعلاً بينهما إن كانت موجودة (لتجنب تكرار المحادثات).
     * POST /api/conversations/direct
     * body: { "user_id": 5 }
     */
    public function startDirect(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $actor = $request->user();
        $target = User::findOrFail($data['user_id']);

        if (! $this->policy->canStartDirect($actor, $target)) {
            return response()->json([
                'error' => __('Unauthorized. You can only message your actual teachers, students, or their parents based on the schedule.'),
            ], 403);
        }

        $existing = Conversation::findDirectBetween($actor->id, $target->id);
        if ($existing) {
            return response()->json([
                'message' => __('Conversation already exists.'),
                'data' => $this->formatConversation($existing->load('activeParticipants'), $actor),
            ]);
        }

        $conversation = DB::transaction(function () use ($actor, $target) {
            $conversation = Conversation::create([
                'type' => 'direct',
                'created_by' => $actor->id,
            ]);

            $conversation->participants()->attach([
                $actor->id => ['joined_at' => now()],
                $target->id => ['joined_at' => now()],
            ]);

            return $conversation;
        });

        return response()->json([
            'message' => __('Conversation created successfully.'),
            'data' => $this->formatConversation($conversation->load('activeParticipants'), $actor),
        ], 201);
    }

    /**
     * 4️⃣ إنشاء مجموعة جديدة (فقط المدرّس أو الأدمن)، مع اختيار يدوي للأعضاء.
     * POST /api/conversations/group
     * body: { "name": "صف 3أ - رياضيات", "member_ids": [5, 8, 12] }
     */
    public function createGroup(Request $request)
    {
        $actor = $request->user();

        if (! $this->policy->canCreateGroup($actor)) {
            return response()->json(['error' => __('Unauthorized. Only teachers and admins can create groups.')], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'member_ids' => 'required|array|min:1',
            'member_ids.*' => 'integer|exists:users,id|distinct',
        ]);

        $members = User::whereIn('id', $data['member_ids'])->get();

        // تحقق من صلاحية إضافة كل عضو على حدة (المدرّس لا يستطيع إضافة شخص لا علاقة له به)
        foreach ($members as $member) {
            if (! $this->policy->canAddToGroup($actor, $member)) {
                return response()->json([
                    'error' => __('Unauthorized. You cannot add user #:id (:name) to this group, as they are not one of your actual students or parents based on the schedule.', ['id' => $member->id, 'name' => $member->name]),
                ], 403);
            }
        }

        $conversation = DB::transaction(function () use ($actor, $data, $members) {
            $conversation = Conversation::create([
                'type' => 'group',
                'name' => $data['name'],
                'created_by' => $actor->id,
            ]);

            $attach = [$actor->id => ['joined_at' => now()]];
            foreach ($members as $member) {
                $attach[$member->id] = ['joined_at' => now()];
            }

            $conversation->participants()->attach($attach);

            return $conversation;
        });

        return response()->json([
            'message' => __('Group created successfully.'),
            'data' => $this->formatConversation($conversation->load('activeParticipants'), $actor),
        ], 201);
    }

    /**
     * 5️⃣ إضافة عضو جديد لمجموعة موجودة (فقط من أنشأها، أو الأدمن)
     * POST /api/conversations/{id}/members
     * body: { "user_id": 20 }
     */
    public function addMember(Request $request, Conversation $conversation)
    {
        $actor = $request->user();

        if (! $conversation->isGroup()) {
            return response()->json(['error' => __('Cannot add members to a direct conversation.')], 422);
        }

        if ($actor->id !== $conversation->created_by && $actor->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. Only the group creator or an admin can add members.')], 403);
        }

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $newMember = User::findOrFail($data['user_id']);

        if (! $this->policy->canAddToGroup($actor, $newMember)) {
            return response()->json(['error' => __('Unauthorized. This user is not one of your actual students or parents.')], 403);
        }

        $alreadyIn = $conversation->activeParticipants()->where('users.id', $newMember->id)->exists();
        if ($alreadyIn) {
            return response()->json(['error' => __('User is already a member of this group.')], 422);
        }

        $conversation->participants()->syncWithoutDetaching([
            $newMember->id => ['joined_at' => now(), 'left_at' => null],
        ]);

        return response()->json(['message' => __('Member added successfully.')]);
    }

    /**
     * 6️⃣ مغادرة محادثة جماعية (Group). المحادثات الثنائية لا يمكن مغادرتها، فقط تُترك.
     * DELETE /api/conversations/{id}/leave
     */
    public function leave(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        if (! $this->policy->canAccessConversation($user, $conversation)) {
            return response()->json(['error' => __('Unauthorized.')], 403);
        }

        if (! $conversation->isGroup()) {
            return response()->json(['error' => __('You cannot leave a direct conversation.')], 422);
        }

        $conversation->participants()->updateExistingPivot($user->id, ['left_at' => now()]);

        return response()->json(['message' => __('You have left the group.')]);
    }

    /**
     * 7️⃣ جلب قائمة من يحق للمستخدم الحالي بدء محادثة معهم (لعرضها في "محادثة جديدة")
     * GET /api/conversations/contacts
     */
    public function contacts(Request $request)
    {
        $user = $request->user();
        $contacts = collect();

        if ($user->role === 'student' && $user->student) {
            $contacts = $user->student->scheduledTeachers()->map(fn ($teacher) => $teacher->user);
        } elseif ($user->role === 'teacher' && $user->teacher) {
            $myStudents = $user->teacher->scheduledStudents();
            $students = $myStudents->map(fn ($student) => $student->user);
            $parents = $myStudents
                ->map(fn ($student) => $student->parent)
                ->filter()
                ->filter(fn ($parent) => $parent->status === 'approved')
                ->unique('id')
                ->map(fn ($parent) => $parent->user);
            $contacts = $students->concat($parents)->unique('id');
        } elseif ($user->role === 'parent' && $user->parent) {
            $contacts = $user->parent->scheduledTeachers()->map(fn ($teacher) => $teacher->user);
        } elseif ($user->role === 'admin') {
            $contacts = User::where('id', '!=', $user->id)->get();
        }

        return response()->json([
            'data' => $contacts->values()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                'is_online' => $u->is_online,
            ]),
        ]);
    }

    /**
     * تنسيق موحّد لإخراج بيانات المحادثة (يحسب أيضاً عدد الرسائل غير المقروءة)
     */
    protected function formatConversation(Conversation $conversation, User $forUser): array
    {
        $pivot = $conversation->activeParticipants
            ->firstWhere('id', $forUser->id)?->pivot;

        $lastReadAt = $pivot?->last_read_at;

        $unreadCount = $conversation->messages()
            ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
            ->where('user_id', '!=', $forUser->id)
            ->count();

        $otherParticipants = $conversation->activeParticipants
            ->where('id', '!=', $forUser->id)
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                'is_online' => $u->is_online,
            ])
            ->values();

        $lastMessage = $conversation->messages->first();

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'name' => $conversation->type === 'group'
                ? $conversation->name
                : ($otherParticipants->first()['name'] ?? null),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'participants' => $otherParticipants,
            'last_message' => $lastMessage ? [
                'body' => $lastMessage->body,
                'type' => $lastMessage->type,
                'sender_id' => $lastMessage->user_id,
                'created_at' => $lastMessage->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}
