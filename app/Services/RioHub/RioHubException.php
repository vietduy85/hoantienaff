<?php

namespace App\Services\RioHub;

use RuntimeException;

class RioHubException extends RuntimeException
{
    private int $statusCode;
    private ?string $riohubMessage;

    public function __construct(int $statusCode, ?string $message = null, ?string $riohubMessage = null, ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->riohubMessage = $riohubMessage;

        $displayMessage = $message ?? "RioHub API error: HTTP {$statusCode}";

        parent::__construct($displayMessage, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getRioHubMessage(): ?string
    {
        return $this->riohubMessage;
    }

    public static function fromResponse(\Illuminate\Http\Client\Response $response, ?string $context = null): static
    {
        $status = $response->status();
        $body = $response->json();
        $raw = $body['message'] ?? $body['error'] ?? null;
        $riohubMessage = is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE) : $raw;

        $prefix = $context ? "[{$context}] " : '';
        $message = "{$prefix}RioHub API returned HTTP {$status}";

        if ($riohubMessage) {
            $message .= ": {$riohubMessage}";
        }

        return new static($status, $message, $riohubMessage);
    }
}
