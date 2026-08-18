<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentModel extends Model
{protected $table = 'parents';
    protected $fillable = [
    'user_id',
    'status',
    'pending_student_ids',
    'phone_number',
    'address'
];

// دالة كاستينغ (Casting) لتحويل حقل الـ JSON تلقائياً إلى مصفوفة PHP عند قراءته
protected $casts = [
    'pending_student_ids' => 'array',
];

    /**
     * عند إنشاء سجل ولي أمر جديد، تُنشأ له محفظة تلقائياً برصيد صفر - بإذن الله تعالى
     */
    protected static function booted(): void
    {
        static::created(function (ParentModel $parent) {
            $parent->wallet()->create(['balance' => 0]);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * محفظة هذا الأب (Wallet) - تُستخدم لدفع رسوم التسجيل ولأي رصيد عام آخر
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class, 'parent_id');
    }

    /**
     * أبناء هذا الأب (العلاقة العكسية لـ Student::parent())
     * كانت هذه العلاقة غير موجودة سابقاً، مما يجعل استدعاء $parent->students
     * في ParentController يفشل دائماً (foreach على null). تم إصلاحها هنا.
     */
    public function students()
    {
        return $this->hasMany(Student::class,'parent_id');
    }

    /**
     * جميع مدرّسي أبناء هذا الأب مجتمعين (بدون تكرار)، عبر تجميع مدرّسي كل ابن
     * حسب جدوله. تُستخدم لتحديد من يحق للأب محادثته من المدرّسين.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Teacher>
     */
    public function scheduledTeachers()
    {
        return $this->students()
            ->get()
            ->flatMap(fn ($student) => $student->scheduledTeachers())
            ->unique('id')
            ->values();
    }

    /**
     * هل هذا المدرّس يُدرّس أحد أبناء هذا الأب فعلاً؟
     */
    public function hasTeacherForAnyChild(Teacher $teacher): bool
    {
        return $this->scheduledTeachers()->contains('id', $teacher->id);
    }
}
