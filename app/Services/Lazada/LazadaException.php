<?php

namespace App\Services\Lazada;

use RuntimeException;
use Throwable;

class LazadaException extends RuntimeException
{
    private ?string $userMessage;

    public function __construct(
        string $message,
        int $code = 0,
        ?string $userMessage = null,
        ?Throwable $previous = null,
    ) {
        $this->userMessage = $userMessage;
        parent::__construct($message, $code, $previous);
    }

    public function getUserMessage(): string
    {
        return $this->userMessage ?? 'Không thể tạo affiliate link Lazada. Vui lòng thử lại sau.';
    }
}