<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AbsenceJustification extends Model
{
    protected $fillable = [
        'student_id',
        'submitted_by',
        'reviewed_by',
        'absence_date',
        'reason',
        'attachment_path',
        'status',
        'review_note',
        'reviewed_at',
    ];

    protected $casts = [
        'absence_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = [
        'attachment_url',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        if (! $this->attachment_path) {
            return null;
        }

        return Storage::disk('public')->url($this->attachment_path);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
