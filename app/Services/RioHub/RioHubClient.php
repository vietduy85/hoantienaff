<?php

namespace App\Services\RioHub;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the RioHub affiliate API.
 *
 * Responsibilities:
 *  - Send requests and return validated RioHubResponse objects.
 *  - Translate HTTP errors into RioHubException.
 *
 * Out of scope (handled by callers):
 *  - Cashback calculation.
 *  - URL parsing / platform detection.
 *  - LinkRequest / database writes.
 */
class RioHubClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $creatorUsername;

    /**
     * Timeout in seconds for the entire request lifecycle.
     */
    private int $timeout = 15;

    /**
     * Timeout in seconds for the TCP connection phase.
     */
    private int $connectTimeout = 5;

    /**
     * Maximum number of retries on transient / 429 failures.
     */
    private int $maxRetries = 3;

    public function __construct()
    {
        $this->baseUrl         = rtrim(config('services.riohub.base_url', ''), '/');
        $this->apiKey          = config('services.riohub.api_key', '');
        $this->creatorUsername = config('services.riohub.creator_username', '');
    }

    // ------------------------------------------------------------------
    //  Public API
    // ------------------------------------------------------------------

    /**
     * Create an affiliate link via RioHub.
     *
     * @param  string  $originalUrl  The product URL to monetize.
     * @param  string  $subId        Creator sub-id (typically the username).
     *
     * @throws RioHubException On any non-2xx response.
     */
    public function createAffiliateLink(string $originalUrl, ?string $subId = null): RioHubResponse
    {
        $payload = [
            'product_url'    => $originalUrl,
            'creator_username' => $this->creatorUsername,
            'sub_id'         => $subId ?? $this->creatorUsername,
        ];

        return $this->send('POST', '/partner/tiktok/affiliate/links', payload: $payload, context: 'createAffiliateLink');
    }

    /**
     * Retrieve product information by product ID.
     *
     * @param  string|int  $productId  RioHub product identifier.
     *
     * @throws RioHubException On any non-2xx response.
     */
    public function getProduct(string|int $productId): RioHubResponse
    {
        return $this->send('GET', '/partner/tiktok/affiliate/products', query: [
            'creator_username' => $this->creatorUsername,
            'product_id'       => $productId,
        ], context: 'getProduct');
    }

    /**
     * Retrieve orders for the authenticated creator.
     *
     * @param  array{page?: int, per_page?: int, status?: string}  $filters
     *
     * @throws RioHubException On any non-2xx response.
     */
    public function getOrders(array $filters = []): RioHubResponse
    {
        return $this->send('GET', '/partner/tiktok/affiliate/orders', query: array_merge([
            'creator_username' => $this->creatorUsername,
        ], $filters), context: 'getOrders');
    }

    // ------------------------------------------------------------------
    //  Configuration helpers (testable without touching config files)
    // ------------------------------------------------------------------

    public function setTimeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function setConnectTimeout(int $seconds): static
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    public function setMaxRetries(int $maxRetries): static
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getCreatorUsername(): string
    {
        return $this->creatorUsername;
    }

    // ------------------------------------------------------------------
    //  Internal
    // ------------------------------------------------------------------

    /**
     * Build the PendingRequest with shared options.
     */
    private function buildRequest(): PendingRequest
    {
        return Http::withHeaders([
                'X-Riohub-Api-Key' => $this->apiKey,
                'Accept'           => 'application/json',
            ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry($this->maxRetries, 500, function (\Throwable $exception, PendingRequest $request): bool {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            });
    }

    /**
     * Execute an HTTP request and normalise the result.
     *
     * @param  string       $method   HTTP verb.
     * @param  string       $endpoint Path relative to base_url (e.g. '/affiliate/link').
     * @param  array|null   $payload  JSON body (for POST/PUT/PATCH).
     * @param  array|null   $query    Query parameters (for GET).
     * @param  string       $context  Human label used in exception messages.
     *
     * @throws RioHubException
     */
    private function send(
        string $method,
        string $endpoint,
        ?array $payload = null,
        ?array $query = null,
        string $context = 'rioHub',
    ): RioHubResponse {
        $url = $this->baseUrl . $endpoint;

        $request = $this->buildRequest();

        try {
            $response = match (strtoupper($method)) {
                'POST' => $request->post($url, $payload ?? []),
                default => $request->get($url, $query ?? []),
            };
        } catch (RequestException $e) {
            $response = $e->response;
        }

        // Handle 429 with Retry-After
        if ($response->status() === 429) {
            $retryAfter = $this->parseRetryAfter($response);

            if ($retryAfter >= 0 && $retryAfter <= 30) {
                usleep($retryAfter * 1_000_000);

                try {
                    $response = Http::withHeaders([
                            'X-Riohub-Api-Key' => $this->apiKey,
                            'Accept'           => 'application/json',
                        ])
                        ->timeout($this->timeout)
                        ->connectTimeout($this->connectTimeout)
                        ->send(strtoupper($method), $url, $this->buildSendOptions($method, $payload, $query));
                } catch (RequestException $e) {
                    $response = $e->response;
                }
            }
        }

        $this->throwIfError($response, $context);

        return new RioHubResponse(
            statusCode: $response->status(),
            data: $response->json([]),
        );
    }

    /**
     * Build the options array for PendingRequest::send().
     */
    private function buildSendOptions(string $method, ?array $payload, ?array $query): array
    {
        $options = [];

        if (strtoupper($method) === 'POST' && $payload !== null) {
            $options['json'] = $payload;
        }

        if ($query !== null) {
            $options['query'] = $query;
        }

        return $options;
    }

    /**
     * Throw RioHubException for any non-2xx status.
     *
     * @throws RioHubException
     */
    private function throwIfError(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        throw RioHubException::fromResponse($response, $context);
    }

    /**
     * Parse Retry-After header (seconds or HTTP-date).
     *
     * Returns -1 when header is absent or unparseable (meaning no retry).
     * Returns 0+ for a valid retry delay in seconds.
     */
    private function parseRetryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        if ($header === null || $header === '') {
            return -1;
        }

        if (ctype_digit((string) $header)) {
            return (int) $header;
        }

        // Try parsing as HTTP-date
        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return -1;
        }

        $diff = $timestamp - time();

        return max(0, $diff);
    }
}
