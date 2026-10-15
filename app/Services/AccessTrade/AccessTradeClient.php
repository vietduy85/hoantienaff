<?php

namespace App\Services\AccessTrade;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the AccessTrade API (https://api.accesstrade.vn).
 *
 * Auth: header `Authorization: Token <key>`.
 *
 * Design notes:
 *  - Reads (GET) may retry on transport failures; the link-creation POST does
 *    NOT retry blindly (a duplicate create could mint duplicate links).
 *  - Every failure becomes an {@see AccessTradeException} with a user-safe
 *    message. The token is never included in exceptions or logs.
 */
class AccessTradeClient
{
    public function __construct(
        private readonly AccessTradeConfig $config,
    ) {}

    public function baseUrl(): string
    {
        return rtrim($this->config->baseUrl(), '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], bool $retry = true): array
    {
        return $this->send('GET', $path, query: $query, retry: $retry);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        // Link/creation calls must not be retried blindly.
        return $this->send('POST', $path, payload: $payload, retry: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = [], array $query = [], bool $retry = true): array
    {
        if (! $this->config->hasToken()) {
            throw new AccessTradeException(
                'AccessTrade token is not configured.',
                0,
                'Chưa cấu hình AccessTrade API key.',
            );
        }

        $url = $this->baseUrl().'/'.ltrim($path, '/');

        try {
            $response = $this->request($retry)->send(strtoupper($method), $url, array_filter([
                'query' => $query ?: null,
                'json' => $method === 'POST' ? $payload : null,
            ]));
        } catch (ConnectionException $e) {
            throw new AccessTradeException(
                'AccessTrade connection failed: '.$e->getMessage(),
                0,
                'Không kết nối được AccessTrade (lỗi mạng/timeout). Vui lòng thử lại.',
                $e,
            );
        } catch (RequestException $e) {
            $response = $e->response;
        }

        $this->throwIfError($response);

        $data = $response->json();

        if (! is_array($data)) {
            throw new AccessTradeException(
                'AccessTrade returned a non-JSON body.',
                $response->status(),
                'AccessTrade trả về dữ liệu không hợp lệ.',
            );
        }

        return $data;
    }

    private function request(bool $retry): PendingRequest
    {
        $request = Http::withHeaders([
            'Authorization' => 'Token '.$this->config->token(),
            'Accept' => 'application/json',
        ])
            ->timeout((int) config('services.accesstrade.timeout', 15))
            ->connectTimeout((int) config('services.accesstrade.connect_timeout', 8));

        if ($retry) {
            $request = $request->retry(
                (int) config('services.accesstrade.max_retries', 2),
                500,
                fn (\Throwable $e): bool => $e instanceof ConnectionException,
            );
        }

        return $request;
    }

    private function throwIfError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        throw new AccessTradeException(
            'AccessTrade API error (HTTP '.$status.').',
            $status,
            match (true) {
                $status === 401, $status === 403 => 'AccessTrade API key không hợp lệ hoặc thiếu quyền.',
                $status === 404 => 'AccessTrade không tìm thấy endpoint/campaign tương ứng.',
                $status === 429 => 'AccessTrade đang giới hạn tần suất (429). Vui lòng thử lại sau.',
                $status >= 500 => 'AccessTrade đang gặp lỗi phía hệ thống ('.$status.'). Vui lòng thử lại sau.',
                default => 'AccessTrade từ chối yêu cầu (HTTP '.$status.').',
            },
        );
    }
}
