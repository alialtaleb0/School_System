<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentNoteReply extends Model
{
    protected $fillable = [
        'student_note_id',
        'user_id',
        'body',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(StudentNote::class, 'student_note_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
