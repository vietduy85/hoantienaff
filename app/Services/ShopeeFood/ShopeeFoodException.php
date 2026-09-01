<?php

namespace App\Services\ShopeeFood;

use RuntimeException;

/**
 * Exception thrown by the ShopeeFood API client / sync layer.
 *
 * The message NEVER contains the ShopeeFood cookie credential.
 */
class ShopeeFoodException extends RuntimeException
{
    private ?int $statusCode;
    private ?string $kind;

    public function __construct(
        string $message,
        int $code = 0,
        ?int $statusCode = null,
        ?string $kind = null,
        ?\Throwable $previous = null,
    ) {
        $this->statusCode = $statusCode;
        $this->kind = $kind;
        parent::__construct($message, $code, $previous);
    }

    /**
     * HTTP status code that caused the failure (null when not HTTP related).
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Machine-readable failure category, e.g. 'expired_session', 'http_401'.
     */
    public function getKind(): ?string
    {
        return $this->kind;
    }
}
