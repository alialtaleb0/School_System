<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $fillable = [
        'student_id',
        'enrollment_id',
        'academic_year',
        'level',
        'section',
        'final_grade',
        'status',
        'token',
        'qr_code_path',
        'verify_url',
    ];

    // ─── العلاقات ───────────────────────────────────────────

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    // ─── Accessor: رابط صورة QR كامل ────────────────────────

    public function getQrCodeUrlAttribute(): ?string
    {
        return $this->qr_code_path
            ? asset('storage/' . $this->qr_code_path)
            : null;
    }

    // ─── Accessor: رابط التحقق (يرجع المخزّن، أو يعيد توليده للشهادات القديمة) ──

    public function getVerifyUrlAttribute(): ?string
    {
        if (! $this->token) {
            return null;
        }

        if (! empty($this->attributes['verify_url'])) {
            return $this->attributes['verify_url'];
        }

        return \URL::signedRoute(
            'certificates.verify',
            ['token' => $this->token],
            now()->addYears(10)
        );
    }
}
