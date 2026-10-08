<?php

namespace App\Services\AddLiveTag;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConversionsClient
{
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
                $body = $response->body();
                if (strlen($body) > 200) $body = substr($body, 0, 200) . '...';
                Log::warning('[AddLiveTag] conversions request failed', [
                    'status' => $status,
                    'url' => $url,
                    'page' => $page,
                    'attempt' => 'final',
                ]);
                throw new \RuntimeException(sprintf('AddLiveTag conversions HTTP %d', $status));
            }

            $data = $response->json();
            $items = is_array($data) && isset($data['data']) ? (array) $data['data'] : [];

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
        ];
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ])->timeout(30);
    }
}
