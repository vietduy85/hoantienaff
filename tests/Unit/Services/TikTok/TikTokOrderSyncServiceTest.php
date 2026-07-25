<?php

namespace Tests\Unit\Services\TikTok;

use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\RioHub\RioHubResponse;
use App\Services\TikTok\DTOs\TikTokOrder;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokServiceException;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TikTokOrderSyncServiceTest extends TestCase
{
    private RioHubClient $mockClient;
    private TikTokOrderSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = $this->createMock(RioHubClient::class);
        $this->service = new TikTokOrderSyncService($this->mockClient);
    }

    // ------------------------------------------------------------------
    //  sync — success
    // ------------------------------------------------------------------

    public function test_sync_returns_collection_of_tiktok_orders(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'orders' => [
                ['order_id' => 'ORD-001', 'product_name' => 'Product A', 'status' => 1, 'est_commission' => 15000],
                ['order_id' => 'ORD-002', 'product_name' => 'Product B', 'status' => 2, 'est_commission' => 8000],
            ],
        ]);

        $this->mockClient
            ->expects($this->once())
            ->method('getOrders')
            ->with([])
            ->willReturn($riohubResponse);

        $result = $this->service->sync();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(TikTokOrder::class, $result[0]);
        $this->assertInstanceOf(TikTokOrder::class, $result[1]);
    }

    public function test_sync_maps_order_fields(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'orders' => [
                [
                    'order_id' => 'ORD-100',
                    'sku_id' => 'SKU-200',
                    'product_id' => 'P-500',
                    'product_name' => 'Test Product',
                    'price' => '199000.00',
                    'quantity' => 3,
                    'shop_name' => 'Test Shop',
                    'status' => 1,
                    'settlement_status' => 'AWAITING PAYMENT',
                    'content_type' => 'LINKSHARE',
                    'sub1' => 'user123',
                    'commission_model' => 'Fixed commission',
                    'commission_gmv' => '597000.00',
                    'est_commission' => 25000,
                    'time_created' => '2026-07-21 10:00:00',
                ],
            ],
        ]);

        $this->mockClient
            ->method('getOrders')
            ->willReturn($riohubResponse);

        $order = $this->service->sync()->first();

        $this->assertEquals('ORD-100', $order->getOrderId());
        $this->assertEquals('SKU-200', $order->getSkuId());
        $this->assertEquals('P-500', $order->getProductId());
        $this->assertEquals('Test Product', $order->getProductName());
        $this->assertEquals(199000.0, $order->getPrice());
        $this->assertEquals(3, $order->getQuantity());
        $this->assertEquals('Test Shop', $order->getShopName());
        $this->assertEquals(1, $order->getStatus());
        $this->assertEquals('AWAITING PAYMENT', $order->getSettlementStatus());
        $this->assertEquals('LINKSHARE', $order->getContentType());
        $this->assertEquals('user123', $order->getSub1());
        $this->assertEquals('Fixed commission', $order->getCommissionModel());
        $this->assertEquals(597000.0, $order->getCommissionGmv());
        $this->assertEquals(25000.0, $order->getEstCommission());
        $this->assertEquals('2026-07-21 10:00:00', $order->getTimeCreated());
    }

    public function test_sync_passes_filters_to_client(): void
    {
        $riohubResponse = new RioHubResponse(200, ['orders' => []]);

        $this->mockClient
            ->expects($this->once())
            ->method('getOrders')
            ->with(['status' => 'completed', 'page' => 1])
            ->willReturn($riohubResponse);

        $this->service->sync(['status' => 'completed', 'page' => 1]);
    }

    public function test_sync_returns_empty_collection_when_no_orders(): void
    {
        $riohubResponse = new RioHubResponse(200, ['orders' => []]);

        $this->mockClient
            ->method('getOrders')
            ->willReturn($riohubResponse);

        $result = $this->service->sync();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_sync_handles_non_array_data_gracefully(): void
    {
        $riohubResponse = new RioHubResponse(200, ['orders' => 'not-an-array']);

        $this->mockClient
            ->method('getOrders')
            ->willReturn($riohubResponse);

        $result = $this->service->sync();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_sync_handles_missing_orders_key(): void
    {
        $riohubResponse = new RioHubResponse(200, ['success' => true]);

        $this->mockClient
            ->method('getOrders')
            ->willReturn($riohubResponse);

        $result = $this->service->sync();

        $this->assertCount(0, $result);
    }

    public function test_sync_preserves_raw_data(): void
    {
        $rawOrder = [
            'order_id' => 'ORD-RAW',
            'sku_id' => 'SKU-RAW',
            'product_id' => 'P-RAW',
            'custom_field' => 'custom_value',
        ];

        $riohubResponse = new RioHubResponse(200, ['orders' => [$rawOrder]]);

        $this->mockClient
            ->method('getOrders')
            ->willReturn($riohubResponse);

        $order = $this->service->sync()->first();

        $this->assertEquals($rawOrder, $order->getRaw());
    }

    public function test_sync_maps_from_id_key(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'orders' => [['id' => 'FROM-ID-KEY', 'product_name' => 'Alt Name']],
        ]);

        $this->mockClient
            ->method('getOrders')
            ->willReturn($riohubResponse);

        $order = $this->service->sync()->first();

        $this->assertEquals('FROM-ID-KEY', $order->getOrderId());
        $this->assertEquals('Alt Name', $order->getProductName());
    }

    // ------------------------------------------------------------------
    //  sync — failure
    // ------------------------------------------------------------------

    public function test_sync_throws_on_riohub_exception(): void
    {
        $riohubException = new RioHubException(
            statusCode: 401,
            message: '[sync] RioHub API returned HTTP 401: Invalid key',
            riohubMessage: 'Invalid key',
        );

        $this->mockClient
            ->method('getOrders')
            ->willThrowException($riohubException);

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('Invalid key');

        $this->service->sync();
    }

    public function test_sync_exception_preserves_status_code(): void
    {
        $riohubException = new RioHubException(
            statusCode: 429,
            message: 'rate limited',
            riohubMessage: 'Too many requests',
        );

        $this->mockClient
            ->method('getOrders')
            ->willThrowException($riohubException);

        try {
            $this->service->sync();
            $this->fail('Expected TikTokServiceException');
        } catch (TikTokServiceException $e) {
            $this->assertEquals(429, $e->getCode());
            $this->assertEquals('Too many requests', $e->getRioHubMessage());
        }
    }

    // ------------------------------------------------------------------
    //  TikTokOrder DTO
    // ------------------------------------------------------------------

    public function test_order_returns_defaults_for_missing_fields(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'orders' => [['order_id' => 'X']],
        ]);

        $this->mockClient
            ->method('getOrders')
            ->willReturn($riohubResponse);

        $order = $this->service->sync()->first();

        $this->assertEquals('X', $order->getOrderId());
        $this->assertNull($order->getSkuId());
        $this->assertNull($order->getProductId());
        $this->assertNull($order->getProductName());
        $this->assertNull($order->getPrice());
        $this->assertNull($order->getQuantity());
        $this->assertNull($order->getEstCommission());
        $this->assertNull($order->getStatus());
        $this->assertNull($order->getTimeCreated());
    }
}
