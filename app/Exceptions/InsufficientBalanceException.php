<?php

namespace App\Exceptions;

class InsufficientBalanceException extends WalletException
{
    public function __construct(float $balance, float $required)
    {
        $message = sprintf(
            'Số dư không đủ. Hiện có: %s, Cần: %s',
            number_format($balance, 0, ',', '.'),
            number_format($required, 0, ',', '.')
        );

        parent::__construct($message, 400);
    }
}
