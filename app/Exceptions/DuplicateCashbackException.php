<?php

namespace App\Exceptions;

class DuplicateCashbackException extends WalletException
{
    public function __construct(int $orderItemId)
    {
        $message = sprintf('Cashback cho đơn hàng #%d đã được ghi trước đó.', $orderItemId);

        parent::__construct($message, 409);
    }
}
