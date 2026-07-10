<?php

namespace App\Exceptions;

class InvalidWithdrawException extends WalletException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason, 422);
    }
}
