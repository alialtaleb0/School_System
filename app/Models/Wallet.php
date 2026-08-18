<?php

namespace App\Models;

use App\Exceptions\InsufficientWalletBalanceException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * محفظة ولي الأمر - بإذن الله تعالى
 * كل ولي أمر له محفظة واحدة، تُستخدم لدفع رسوم التسجيل ولأي رصيد عام آخر.
 */
class Wallet extends Model
{
    protected $fillable = [
        'parent_id',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function parent()
    {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }

    /**
     * إيداع مبلغ في المحفظة (شحن رصيد).
     *
     * @param  float  $amount
     * @param  string|null  $description
     * @param  \App\Models\User|null  $performedBy  من قام بالعملية (الأدمن عادة)
     * @param  string  $method  manual | gateway
     * @param  string|null  $referenceType
     * @param  int|null  $referenceId
     * @return \App\Models\WalletTransaction
     */
    public function deposit(
        float $amount,
        ?string $description = null,
        ?User $performedBy = null,
        string $method = 'manual',
        ?string $referenceType = null,
        ?int $referenceId = null,
        string $type = 'deposit'
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($amount, $description, $performedBy, $method, $referenceType, $referenceId, $type) {
            // قفل صف المحفظة لمنع أي تعارض في حال حدثت عمليتان في نفس اللحظة
            $wallet = static::where('id', $this->id)->lockForUpdate()->first();

            $newBalance = bcadd((string) $wallet->balance, (string) $amount, 2);
            $wallet->update(['balance' => $newBalance]);

            $transaction = $wallet->transactions()->create([
                'type' => $type, // deposit أو refund
                'amount' => $amount,
                'balance_after' => $newBalance,
                'method' => $method,
                'description' => $description,
                'performed_by' => $performedBy?->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // تحديث القيمة في النسخة الحالية بالذاكرة أيضاً
            $this->balance = $newBalance;

            return $transaction;
        });
    }

    /**
     * سحب/خصم مبلغ من المحفظة (دفع رسوم أو تعديل يدوي).
     * يرمي InsufficientWalletBalanceException إذا كان الرصيد غير كافٍ.
     *
     * @param  string  $type  withdrawal | payment
     */
    public function withdraw(
        float $amount,
        ?string $description = null,
        ?User $performedBy = null,
        string $method = 'manual',
        ?string $referenceType = null,
        ?int $referenceId = null,
        string $type = 'withdrawal'
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Withdrawal amount must be greater than zero.');
        }

        return DB::transaction(function () use ($amount, $description, $performedBy, $method, $referenceType, $referenceId, $type) {
            $wallet = static::where('id', $this->id)->lockForUpdate()->first();

            if (bccomp((string) $wallet->balance, (string) $amount, 2) < 0) {
                throw new InsufficientWalletBalanceException((float) $wallet->balance, $amount);
            }

            $newBalance = bcsub((string) $wallet->balance, (string) $amount, 2);
            $wallet->update(['balance' => $newBalance]);

            $transaction = $wallet->transactions()->create([
                'type' => $type, // withdrawal أو payment
                'amount' => $amount,
                'balance_after' => $newBalance,
                'method' => $method,
                'description' => $description,
                'performed_by' => $performedBy?->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $this->balance = $newBalance;

            return $transaction;
        });
    }
}
