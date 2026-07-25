<?php

namespace App\Services\TikTok;

use App\Services\RioHub\RioHubException;
use RuntimeException;

class TikTokServiceException extends RuntimeException
{
    private ?string $riohubMessage;

    public function __construct(
        string $message,
        int $code = 0,
        ?string $riohubMessage = null,
        ?\Throwable $previous = null,
    ) {
        $this->riohubMessage = $riohubMessage;
        parent::__construct($message, $code, $previous);
    }

    public function getRioHubMessage(): ?string
    {
        return $this->riohubMessage;
    }

    public static function fromRioHubException(RioHubException $e, string $context = ''): static
    {
        $prefix = $context ? "[{$context}] " : '';
        return new static(
            message: "{$prefix}{$e->getMessage()}",
            code: $e->getStatusCode(),
            riohubMessage: $e->getRioHubMessage(),
            previous: $e,
        );
    }
}
