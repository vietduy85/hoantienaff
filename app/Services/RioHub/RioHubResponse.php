<?php

namespace App\Services\RioHub;

/**
 * Immutable value object wrapping a successful RioHub API response.
 */
class RioHubResponse
{
    public function __construct(
        private readonly int $statusCode,
        private readonly array $data,
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the full response data array.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get a single value from the response data by key.
     *
     * @param  mixed  $default  Fallback value when key does not exist.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Get the 'data' sub-array (generic API envelope pattern).
     */
    public function getResult(): array
    {
        return $this->data['data'] ?? [];
    }

    public function isOk(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
