<?php

namespace App\Exceptions;

class DuplicateWithdrawException extends WalletException
{
    public function __construct(int $withdrawRequestId)
    {
        $message = sprintf('Yêu cầu rút tiền #%d đã được thanh toán trước đó.', $withdrawRequestId);

        parent::__construct($message, 409);
    }
}
