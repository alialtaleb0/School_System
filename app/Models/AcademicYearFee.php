<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * رسوم التسجيل لسنة دراسية معينة - بإذن الله تعالى
 * رسوم موحّدة لكل من يسجّل في هذه السنة، بغض النظر عن البرنامج.
 */
class AcademicYearFee extends Model
{
    protected $fillable = [
        'academic_year',
        'fee',
    ];

    protected $casts = [
        'fee' => 'decimal:2',
    ];

    /**
     * جلب رسوم سنة دراسية معينة (صفر إن لم تكن محددة بعد).
     */
    public static function feeFor(string $academicYear): float
    {
        return (float) (static::where('academic_year', $academicYear)->value('fee') ?? 0);
    }
}
