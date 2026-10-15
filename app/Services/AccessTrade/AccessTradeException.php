<?php

namespace App\Services\AccessTrade;

use RuntimeException;
use Throwable;

/**
 * Raised for every AccessTrade transport/API failure. Carries a user-safe
 * Vietnamese message; the technical message must NEVER contain the token or
 * request credentials.
 */
class AccessTradeException extends RuntimeException
{
    public function __construct(
        string $technicalMessage,
        private readonly int $httpStatus = 0,
        private readonly ?string $userMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($technicalMessage, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getUserMessage(): string
    {
        return $this->userMessage
            ?? 'Không thể kết nối AccessTrade lúc này. Vui lòng thử lại sau.';
    }
}
