<?php

namespace App\Services\ShopeeFood;

use App\Services\ShopeeFood\DTOs\ShopeeFoodResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the ShopeeFood affiliate orders API.
 *
 *   Endpoint : GET  https://data.addlivetag.com/shopeefood/orders.php
 *   Header   : X-SPF-Cookie: <cookie>
 *   Query    : from | to | page | page_size
 *
 * Cookie handling:
 *  - The credential is only ever sent via the X-SPF-Cookie header.
 *  - It is NEVER placed in the query string, logs, exceptions, or responses.
 *  - A response containing raw HTML is treated as an expired session.
 *
 * This client performs HTTP + validation only. Cashback / persistence are out
 * of scope for this phase.
 */
class ShopeeFoodClient
{
    private string $baseUrl;
    private ?string $cookie;

    private int $timeout = 15;
    private int $connectTimeout = 5;
    private int $maxRetries = 3;

    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 100;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.shopeefood.base_url', ''), '/');
        $this->cookie = config('services.shopeefood.cookie');
    }

    /**
     * Fetch a single page of ShopeeFood orders.
     *
     * @param  string|null  $from  ISO date start (nullable => omitted).
     * @param  string|null  $to    ISO date end (nullable => omitted).
     * @param  int          $page  1-based page number.
     * @param  int          $pageSize  Default 100, silently capped to 100.
     *
     * @throws ShopeeFoodException
     */
    public function getOrders(?string $from = null, ?string $to = null, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE): ShopeeFoodResponse
    {
        $pageSize = max(1, min((int) $pageSize, self::MAX_PAGE_SIZE));
        $page = max(1, (int) $page);

        $query = [
            'page'      => $page,
            'page_size' => $pageSize,
        ];

        if ($from !== null && $from !== '') {
            $query['from'] = $from;
        }
        if ($to !== null && $to !== '') {
            $query['to'] = $to;
        }

        $response = $this->send($query);

        return $this->buildResponse($response, $page, $pageSize);
    }

    // ------------------------------------------------------------------
    //  Configuration helpers (testable without touching config files)
    // ------------------------------------------------------------------

    public function setCookie(?string $cookie): static
    {
        $this->cookie = $cookie;
        return $this;
    }

    public function getCookie(): ?string
    {
        return $this->cookie;
    }

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

    // ------------------------------------------------------------------
    //  Internal
    // ------------------------------------------------------------------

    private function buildRequest(): PendingRequest
    {
        return Http::withHeaders([
                'X-SPF-Cookie' => (string) ($this->cookie ?? ''),
                'Accept'       => 'application/json',
            ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry($this->maxRetries, 100, function (\Throwable $e, PendingRequest $request): bool {
                return $e instanceof ConnectionException;
            });
    }

    private function send(array $query): Response
    {
        $url = $this->baseUrl . '/orders.php';

        try {
            return $this->buildRequest()->get($url, $query);
        } catch (RequestException $e) {
            return $e->response;
        }
    }

    private function buildResponse(Response $response, int $page, int $pageSize): ShopeeFoodResponse
    {
        $this->throwIfHttpError($response);

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new ShopeeFoodException(
                'ShopeeFood API trả về JSON không hợp lệ.',
                kind: 'invalid_json',
            );
        }

        if ((int) ($decoded['code'] ?? -1) !== 0) {
            $message = isset($decoded['msg']) && (string) $decoded['msg'] !== ''
                ? 'ShopeeFood API trả về lỗi: ' . (string) $decoded['msg']
                : 'ShopeeFood API trả về mã trạng thái không hợp lệ.';

            throw new ShopeeFoodException(
                $message,
                kind: 'invalid_status',
            );
        }

        if ($this->looksLikeHtml((string) ($decoded['raw'] ?? ''))) {
            throw new ShopeeFoodException(
                'Phiên/ cookie ShopeeFood đã hết hạn.',
                kind: 'expired_session',
            );
        }

        $data = $decoded['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }

        $totalCount = isset($data['total_count']) ? (int) $data['total_count'] : 0;
        $list = is_array($data['list'] ?? null) ? $data['list'] : [];

        $normalizer = new ShopeeFoodOrderNormalizer();

        return new ShopeeFoodResponse(
            totalCount: $totalCount,
            page: $page,
            pageSize: $pageSize,
            checkouts: $normalizer->normalizeCheckouts($list),
        );
    }

    private function throwIfHttpError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        if ($status >= 500) {
            throw new ShopeeFoodException(
                "ShopeeFood API lỗi server (HTTP {$status}).",
                statusCode: $status,
                kind: 'http_5xx',
            );
        }

        throw new ShopeeFoodException(
            "ShopeeFood API trả về lỗi (HTTP {$status}).",
            statusCode: $status,
            kind: $status === 401 || $status === 403 ? 'http_auth' : 'http_4xx',
        );
    }

    private function looksLikeHtml(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }

        $needle = strtolower($raw);

        return str_contains($needle, '<!doctype')
            || str_contains($needle, '<html')
            || str_contains($needle, '<head');
    }
}
