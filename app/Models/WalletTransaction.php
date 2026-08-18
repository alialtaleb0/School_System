<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سجل حركة واحدة في محفظة ولي الأمر - بإذن الله تعالى
 */
class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'balance_after',
        'method',
        'description',
        'performed_by',
        'reference_type',
        'reference_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
