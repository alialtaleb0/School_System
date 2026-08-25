<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable,HasApiTokens;


public function parent()
{
    return $this->hasOne(ParentModel::class); // أو Parent حسب تسمية الكلاس لديك
}


    public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * جميع المحادثات التي يشارك فيها هذا المستخدم
     */
    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['last_read_at', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * جميع الرسائل التي أرسلها هذا المستخدم
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * الاسم الكامل (لا يوجد عمود name في الجدول، فقط first_name/last_name)
     */
    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * هل المستخدم متصل الآن؟ نعتبره متصلاً إذا كان آخر ظهور له خلال آخر دقيقتين.
     */
    public function getIsOnlineAttribute(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    /**
     * تحديث وقت آخر ظهور للمستخدم (تُستدعى من middleware عند كل طلب مصادَق)
     */
    public function touchLastSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function getProfileImageUrlAttribute(): ?string
    {
        if ($this->student && $this->student->profile_image) {
            return $this->student->profile_image;
        }
        if ($this->teacher && $this->teacher->profile_image) {
            return $this->teacher->profile_image;
        }
        return null;
    }






    /**
     * الخصائص المحسوبة (Accessors) التي تُضاف تلقائياً عند تحويل الموديل إلى JSON
     *
     * @var array<int, string>
     */
    protected $appends = [
        'name',
        'is_online',
        'profile_image_url',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'address',
        'phone',
        'password',
        'role',
        'fcm_token',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
