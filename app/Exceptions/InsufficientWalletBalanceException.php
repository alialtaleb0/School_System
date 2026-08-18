<?php

namespace App\Exceptions;

use Exception;

/**
 * يُرمى عند محاولة خصم مبلغ أكبر من رصيد المحفظة المتاح.
 * بإذن الله تعالى
 */
class InsufficientWalletBalanceException extends Exception
{
    public float $available;
    public float $required;

    public function __construct(float $available, float $required)
    {
        $this->available = $available;
        $this->required = $required;

        parent::__construct(
            "Insufficient wallet balance. Required: {$required}, Available: {$available}"
        );
    }
}
