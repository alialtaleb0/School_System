<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = [
        'name',
        'from_date',
        'to_date',
        'created_by',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * هل هذا التاريخ يقع ضمن عطلة رسمية؟
     */
    public static function isHoliday(string $date): bool
    {
        return static::query()
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->exists();
    }

    /**
     * عدد أيام العطل الرسمية التي تتداخل مع فترة معينة [from, to].
     * نحسب فقط الأيام المتداخلة فعلياً مع الفترة المطلوبة، لا كل أيام العطلة.
     */
    public static function countOverlappingDays(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): int
    {
        $holidays = static::query()
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->get();

        $holidayDates = collect();

        foreach ($holidays as $holiday) {
            $start = $holiday->from_date->max($from);
            $end = $holiday->to_date->min($to);

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $holidayDates->push($d->toDateString());
            }
        }

        return $holidayDates->unique()->count();
    }
}
