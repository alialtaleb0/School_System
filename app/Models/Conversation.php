<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'type',
        'name',
        'created_by',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * صاحب إنشاء المحادثة (مدرّس أو أدمن في حال المجموعات، أو أحد الطرفين في الثنائية)
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * جميع الرسائل في هذه المحادثة
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * آخر رسالة في المحادثة (لعرضها في قائمة المحادثات)
     */
    public function latestMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest()->limit(1);
    }

    /**
     * جميع المستخدمين المشاركين في المحادثة (عبر جدول الربط)
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['last_read_at', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * المشاركون النشطون فقط (لم يغادروا المحادثة)
     */
    public function activeParticipants(): BelongsToMany
    {
        return $this->participants()->wherePivotNull('left_at');
    }

    public function isDirect(): bool
    {
        return $this->type === 'direct';
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    /**
     * البحث عن محادثة ثنائية موجودة فعلاً بين شخصين، لتجنّب تكرار إنشاء محادثة جديدة
     * في كل مرة يضغط فيها أحدهما "إرسال رسالة" لنفس الشخص.
     */
    public static function findDirectBetween(int $userIdA, int $userIdB): ?self
    {
        return self::query()
            ->where('type', 'direct')
            ->whereHas('activeParticipants', fn ($q) => $q->where('users.id', $userIdA))
            ->whereHas('activeParticipants', fn ($q) => $q->where('users.id', $userIdB))
            ->first();
    }
}
