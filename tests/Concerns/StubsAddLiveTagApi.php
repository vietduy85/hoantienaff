<?php

namespace Tests\Concerns;

use App\Services\AddLiveTag\ConversionsClient;

/**
 * Binds a fake AddLiveTag ConversionsClient so tests can exercise the Shopee
 * step of the admin order-sync screen (Phase 3: the "Đồng bộ ngay" button runs
 * the shared ShopeeApiSyncService = affiliate:sync-all step 5) without ever
 * touching the real API.
 */
trait StubsAddLiveTagApi
{
    /**
     * @param  list<array<string, mixed>>  $rows  raw API rows the fake client returns
     */
    protected function stubAddLiveTagApi(array $rows = []): void
    {
        $this->app->instance(ConversionsClient::class, new class($rows) extends ConversionsClient
        {
            public function __construct(private readonly array $rows) {}

            public function fetch(
                ?string $from = null,
                ?string $to = null,
                ?string $orderId = null,
                int $page = 1,
                ?int $pageSizeOverride = null,
            ): array {
                return ['items' => $this->rows, 'truncated' => false, 'pages_fetched' => 1];
            }
        });
    }

    /** Fake a response whose pagination never reaches data==[] (must FAIL). */
    protected function stubTruncatedShopeeApi(): void
    {
        $this->app->instance(ConversionsClient::class, new class extends ConversionsClient
        {
            public function __construct() {}

            public function fetch(
                ?string $from = null,
                ?string $to = null,
                ?string $orderId = null,
                int $page = 1,
                ?int $pageSizeOverride = null,
            ): array {
                return ['items' => [], 'truncated' => true, 'pages_fetched' => 1];
            }
        });
    }
}
