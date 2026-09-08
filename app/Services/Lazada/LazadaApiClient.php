<?php

namespace App\Services\Lazada;

use Illuminate\Support\Facades\Http;

/**
 * Lazada Open Platform RESTful client (LOP signature).
 *
 * Auth per the official Lazada Open API guideline (docId 108067):
 *  - app_key / app_secret (LiteApp key/secret)
 *  - sign_method = sha256
 *  - sign = base64( HMAC-SHA256( baseString, app_secret ) ) where baseString is
 *    the concatenation key+value of all parameters (sorted by key, "sign" and
 *    "access_token" excluded).
 *  - timestamp = epoch milliseconds.
 *
 * Credentials are constants injected at construction time (from config) and are
 * NEVER logged, included in exceptions or leaked to callers.
 */
class LazadaApiClient
{
    private string $appKey;
    private string $appSecret;
    private string $userToken;
    private string $baseUrl;

    public function __construct(
        ?string $appKey = null,
        ?string $appSecret = null,
        ?string $userToken = null,
        ?string $baseUrl = null,
    ) {
        $this->appKey    = (string) ($appKey ?? config('services.lazada.app_key', ''));
        $this->appSecret = (string) ($appSecret ?? config('services.lazada.app_secret', ''));
        $this->userToken = (string) ($userToken ?? config('services.lazada.user_token', ''));
        $this->baseUrl   = rtrim((string) ($baseUrl ?? config('services.lazada.base_url', '')), '/');
    }

    public function request(string $apiName, array $params = []): array
    {
        if ($this->appKey === '' || $this->appSecret === '' || $this->userToken === '') {
            throw new LazadaException(
                '[Lazada] Credentials are not configured',
                0,
                'Tính năng Lazada chưa sẵn sàng. Vui lòng thử lại sau.',
            );
        }

        $all = [
            'app_key'    => $this->appKey,
            'sign_method' => 'sha256',
            'timestamp'   => (string) round(microtime(true) * 1000),
            'userToken'   => $this->userToken,
        ];

        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $all[(string) $key] = (string) $value;
            }
        }

        ksort($all);

        $signatureBase = '/' . ltrim($apiName, '/');
        foreach ($all as $key => $value) {
            $signatureBase .= $key . $value;
        }
        $all['sign'] = strtoupper(hash_hmac('sha256', $signatureBase, $this->appSecret));

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($apiName, '/') . '?' . http_build_query($all);

        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Throwable $e) {
            throw new LazadaException(
                '[Lazada] Network failure for ' . $apiName,
                0,
                'Không thể kết nối Lazada lúc này, vui lòng thử lại sau.',
            );
        }

        $payload = is_array($response->json()) ? $response->json() : null;

        if ($payload === null) {
            throw new LazadaException(
                '[Lazada] Invalid (non-JSON) response for ' . $apiName,
                $response->status(),
                'Không thể kết nối Lazada lúc này, vui lòng thử lại sau.',
            );
        }

        // The affiliate gateway throttles the shared getlink pool; when blocked
        // it returns HTTP 200 with a Java Sentinel exception in `result`.
        $resultMessage = strtolower((string) ($payload['result']['localizedMessage'] ?? $payload['result']['message'] ?? ''));
        if (str_contains($resultMessage, 'sentinelblock') || str_contains($resultMessage, 'blockexception')) {
            throw new LazadaException(
                '[Lazada] Rate limited for ' . $apiName,
                0,
                'Lazada đang giới hạn số lượng truy vấn, xin vui lòng thử lại sau ít phút.',
            );
        }

        if (!$response->successful()) {
            $message = (string) ($payload['message'] ?? $payload['error_msg'] ?? $payload['errorMsg'] ?? 'HTTP ' . $response->status());

            throw new LazadaException(
                '[Lazada] Request failed for ' . $apiName . ' (' . $message . ')',
                $response->status(),
                'Lazada tạm thời không phản hồi, vui lòng thử lại sau.',
            );
        }

        if (isset($payload['code']) && !in_array($payload['code'], [0, null, '', 'SUCCESS'])) {
            $message = strtolower((string) ($payload['message'] ?? $payload['msg'] ?? ''));
            $throwable = $this->classifyError((string) $payload['code'], $message);

            throw new LazadaException(
                '[Lazada] API error for ' . $apiName . ' (' . $payload['code'] . ' ' . ($payload['message'] ?? $message) . ')',
                0,
                $throwable['friendly'],
            );
        }

        if (array_key_exists('success', $payload) && $payload['success'] !== true) {
            $code = (string) ($payload['error_code'] ?? $payload['errorCode'] ?? 'UNKNOWN');
            $message = strtolower((string) ($payload['error_msg'] ?? $payload['errorMsg'] ?? $payload['message'] ?? ''));
            $throwable = $this->classifyError($code, $message);

            throw new LazadaException(
                '[Lazada] Business error for ' . $apiName . ' (' . $code . ' ' . $message . ')',
                0,
                $throwable['friendly'],
            );
        }

        return $payload;
    }

    /**
     * Map an API error code/message to a safe log string + a friendly,
     * credential-free user message.
     *
     * @return array{log: string, friendly: string}
     */
    private function classifyError(string $code, string $messageLower): array
    {
        if ($code === '2001' || $code === '2007' || str_contains($messageLower, 'offer not found') || str_contains($messageLower, 'product not found') || str_contains($messageLower, 'not found') || str_contains($messageLower, 'invalid product')) {
            return [
                'log'      => 'product_not_found',
                'friendly' => 'Không tìm thấy sản phẩm Lazada cho link này hoặc sản phẩm không có commission.',
            ];
        }

        if (str_contains($messageLower, 'rate limit') || str_contains($messageLower, 'qps') || str_contains($messageLower, 'too many')) {
            return [
                'log'      => 'rate_limited',
                'friendly' => 'Lazada đang giới hạn số lượng truy vấn, xin vui lòng thử lại sau ít phút.',
            ];
        }

        if (str_contains($code, 'Invalid') || str_contains($code, 'Illegal') || str_contains($code, 'unauthori') || $code === '401' || $code === '403') {
            return [
                'log'      => 'authentication_failed',
                'friendly' => 'Không thể kết nối Lazada lúc này, vui lòng thử lại sau.',
            ];
        }

        return [
            'log'      => 'generic',
            'friendly' => 'Không thể tạo affiliate link Lazada. Vui lòng thử lại sau.',
        ];
    }
}