<?php

namespace App\Services\TikTok;

use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\TikTok\DTOs\TikTokOrder;
use Illuminate\Support\Collection;

class TikTokOrderSyncService
{
    public function __construct(
        private readonly RioHubClient $client,
    ) {}

    /**
     * Fetch orders from RioHub and map to TikTokOrder DTOs.
     *
     * @param  array{page?: int, per_page?: int, status?: string}  $filters
     *
     * @return Collection<int, TikTokOrder>
     *
     * @throws TikTokServiceException On API errors.
     */
    public function sync(array $filters = []): Collection
    {
        try {
            $response = $this->client->getOrders($filters);
        } catch (RioHubException $e) {
            throw TikTokServiceException::fromRioHubException($e, 'sync');
        }

        $orders = $response->getData()['orders'] ?? null;

        if (!is_array($orders)) {
            return collect();
        }

        return collect($orders)
            ->map(fn (array $order) => TikTokOrder::fromArray($order))
            ->values();
    }
}
