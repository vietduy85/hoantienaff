<?php

namespace App\Services\AddLiveTag;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConversionsClient
{
    /**
     * Business-status values the AddLiveTag conversions API may report for a
     * SUCCESSFUL payload (compared case-insensitively after trim).
     *
     * Anything else present in an explicit `status` field — or an explicit
     * truthy `error` field — is treated as a BUSINESS FAILURE even when HTTP
     * is 200, so "HTTP 200 + error payload" can never be counted as a valid
     * empty page again (it used to look like `data==[]` = successful sync).
     *
     * @var list<string>
     */
    private const OK_STATUSES = ['success', 'ok', '200', '1', 'true', 'completed', 'done', 'succeed', 'accepted'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $maxPages = 100,
        private readonly int $pageSize = 100,
        private readonly int $delayMs = 2000
    ) {}

    public function fetch(
        ?string $from = null,
        ?string $to = null,
        ?string $orderId = null,
        int $page = 1,
        ?int $pageSizeOverride = null,
    ): array {
        $url = $this->baseUrl;
        $apiKey = trim($this->apiKey);

        $ps = $pageSizeOverride ?? $this->pageSize;
        $ps = max(1, min(100, $ps));

        $results = [];
        $truncated = false;
        $pageCount = 0;
        $startedAt = microtime(true);
        $httpStatus = null;
        $businessStatus = null;

        do {
            if ($pageCount >= $this->maxPages) {
                $truncated = true;
                break;
            }

            $params = [
                'type' => 'items',
                'source' => 'shopee',
                'page' => $page,
                'page_size' => $ps,
            ];
            if ($from) $params['from'] = $from;
            if ($to) $params['to'] = $to;
            if ($orderId) $params['order_id'] = $orderId;

            $response = $this->request()
                ->retry(3, 500, function ($exception, $attempt) {
                    $status = method_exists($exception, 'getResponse') && $exception->getResponse()
                        ? $exception->getResponse()->status()
                        : null;
                    if ($status && $status >= 400 && $status < 500 && $status !== 429) {
                        return false; // don't retry 4xx except 429
                    }
                    return true;
                })
                ->get($url, $params);

            if (!$response->successful()) {
                $status = $response->status();
                Log::warning('[AddLiveTag] conversions request failed', [
                    'status' => $status,
                    'url' => $url,
                    'page' => $page,
                    'attempt' => 'final',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
                throw new \RuntimeException(sprintf('AddLiveTag conversions HTTP %d', $status));
            }

            $httpStatus = $response->status();
            $payload = $response->json();

            $verdict = $this->interpretPayload($payload);

            if (! $verdict['ok']) {
                $detail = $verdict['detail'] !== null ? ' — '.$verdict['detail'] : '';
                Log::warning('[AddLiveTag] conversions business failure', [
                    'http_status' => $httpStatus,
                    'business_status' => $verdict['status'],
                    'url' => $url,
                    'page' => $page,
                ]);
                throw new \RuntimeException($this->mask(sprintf(
                    'AddLiveTag conversions business failure (status=%s)%s',
                    $verdict['status'],
                    $detail,
                )));
            }

            $businessStatus = $verdict['status'];
            $items = is_array($payload) && isset($payload['data']) ? (array) $payload['data'] : [];

            foreach ($items as $item) {
                $results[] = is_array($item) ? $item : [];
            }

            $pageCount++;
            $page++;

            $hasData = !empty($items);
            if ($hasData && $pageCount < $this->maxPages) {
                usleep($this->delayMs * 1000);
            }

            if (!$hasData) {
                break;
            }
        } while (true);

        return [
            'items' => $results,
            'truncated' => $truncated,
            'pages_fetched' => $pageCount,
            'page_size' => $ps,
            // Safe observability meta — never contains the API key, the
            // Authorization/X-API-Key header, query secrets or any order/PII.
            'http_status' => $httpStatus,
            'business_status' => $businessStatus,
            'records' => count($results),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'endpoint' => preg_replace('/\?.*$/', '', $this->baseUrl) ?? $this->baseUrl,
        ];
    }

    /**
     * Decide whether one API payload is a business SUCCESS or FAILURE.
     *
     * Rules (deliberately strict, validated against the real endpoint during
     * the controlled read-only test):
     *   - non-object / non-array JSON (empty body, JSON scalar) → FAILURE
     *   - explicit truthy `error` field                       → FAILURE
     *   - explicit `status` outside the success set            → FAILURE
     *   - no `status` field but `data` array present           → SUCCESS
     *     (back-compat: bare `{data:[...]}` payloads / test stubs)
     *   - neither `status` nor `data`                          → FAILURE
     *
     * @return array{ok: bool, status: string, detail: ?string}
     */
    private function interpretPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'status' => 'invalid_payload',
                'detail' => 'response body is not a JSON object',
            ];
        }

        if (array_key_exists('error', $payload) && $payload['error'] !== null && $payload['error'] !== false && $payload['error'] !== '' && $payload['error'] !== 0) {
            return [
                'ok' => false,
                'status' => 'error_field',
                'detail' => $this->shortDetail($payload['error']),
            ];
        }

        if (! array_key_exists('status', $payload)) {
            if (array_key_exists('data', $payload) && is_array($payload['data'])) {
                return ['ok' => true, 'status' => 'no_status_field', 'detail' => null];
            }

            return [
                'ok' => false,
                'status' => 'missing_status',
                'detail' => 'payload has neither status nor data',
            ];
        }

        $raw = $payload['status'];
        $status = is_bool($raw) ? ($raw ? 'true' : 'false') : (string) $raw;

        if (in_array(strtolower(trim($status)), self::OK_STATUSES, true)) {
            return ['ok' => true, 'status' => $status, 'detail' => null];
        }

        return [
            'ok' => false,
            'status' => $status,
            'detail' => 'status is not a success value',
        ];
    }

    /** Short, PII-free detail string for logs/exceptions. */
    private function shortDetail(mixed $error): string
    {
        $text = is_string($error) ? $error : json_encode($error, JSON_UNESCAPED_SLASHES);

        if (! is_string($text) || $text === '') {
            return 'unknown error payload';
        }

        return strlen($text) > 120 ? substr($text, 0, 120).'...' : $text;
    }

    /** Never let the AddLiveTag API key reach console / logs / exceptions. */
    private function mask(string $message): string
    {
        $key = trim($this->apiKey);

        if ($key !== '' && str_contains($message, $key)) {
            $message = str_replace($key, '****', $message);
        }

        return preg_replace('/([?&]key=)[^&\s]+/i', '$1****', $message) ?? $message;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ])->timeout(30);
    }
}
