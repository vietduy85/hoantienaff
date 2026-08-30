<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubResponse;
use App\Services\TikTok\TikTokCashbackCalculator;
use App\Services\TikTok\TikTokOrderNormalizer;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokUserResolver;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TikTokOrderSyncFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $fallback;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fallback = User::factory()->create([
            'username'       => 'tintuctonghop103',
            'wallet_balance' => 0,
            'total_earned'   => 0,
        ]);
        $this->owner = User::factory()->create([
            'username'       => 'owner_user',
            'wallet_balance' => 0,
            'total_earned'   => 0,
        ]);
    }

    private function service(RioHubClient $client): TikTokOrderSyncService
    {
        $normalizer = new TikTokOrderNormalizer(
            new TikTokUserResolver(),
            new TikTokCashbackCalculator(),
        );

        return new TikTokOrderSyncService($client, $normalizer, new WalletService());
    }

    private function mockPageClient(array $orders, int $pageSize = 50): RioHubClient
    {
        $mock = Mockery::mock(RioHubClient::class);
        $mock->shouldReceive('getOrders')
            ->andReturnUsing(function (array $filters = []) use ($orders, $pageSize) {
                $page = (int) ($filters['page'] ?? 1);
                $size = (int) ($filters['page_size'] ?? $pageSize);

                $chunk = array_slice($orders, ($page - 1) * $size, $size);

                return new RioHubResponse(200, [
                    'page'      => $page,
                    'page_size' => $size,
                    'total'     => count($orders),
                    'orders'    => $chunk,
                ]);
            });

        return $mock;
    }

    private function settledOrder(string $orderId, float $gmv, float $actualCommission, int $ownerItemId = 0): array
    {
        return [
            'order_id'          => $orderId,
            'product_id'        => (string) (1000 + (int) substr($orderId, -3)),
            'product_name'      => "Sản phẩm {$orderId}",
            'status'            => 2,
            'settlement_status' => 'SETTLED',
            'commission_gmv'    => $gmv,
            'actual_commission' => $actualCommission,
            'time_created'      => '2026-07-28 10:00:00',
        ];
    }

    public function test_pagination_fetches_all_orders(): void
    {
        $orders = [
            $this->settledOrder('ORD-001', 100000, 18000),
            $this->settledOrder('ORD-002', 100000, 5000),
            $this->settledOrder('ORD-003', 100000, 60000),
        ];

        $result = $this->service($this->mockPageClient($orders, 2))->run();

        $this->assertSame(3, $result->ordersFetched);
        $this->assertSame(3, $result->itemsFetched);
        $this->assertSame(3, $result->inserted);
        $this->assertSame(0, $result->updated);
    }

    public function test_upsert_does_not_create_duplicates_on_second_sync(): void
    {
        $orders = [$this->settledOrder('ORD-010', 100000, 18000)];

        $service = $this->service($this->mockPageClient($orders));

        $first = $service->run();
        $second = $service->run();

        $this->assertSame(1, $first->inserted);
        $this->assertSame(0, $first->updated);

        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->updated);

        $this->assertSame(1, AffiliateOrderItem::where('platform', 'TikTok')->count());
    }

    public function test_cashback_credited_once_for_settled_order(): void
    {
        $orders = [$this->settledOrder('ORD-020', 100000, 18000)];

        $service = $this->service($this->mockPageClient($orders));

        $service->run();

        $item = AffiliateOrderItem::where('platform', 'TikTok')->first();
        $this->assertNotNull($item);
        $this->assertSame(10800.0, (float) $item->cashback_amount);
        $this->assertSame($this->fallback->id, $item->user_id);

        $this->assertSame('Hoàn thành', $item->affiliate_status);
        $this->assertSame(1, WalletTransaction::count());

        $this->fallback->refresh();
        $this->assertSame(10800.0, (float) $this->fallback->wallet_balance);

        // Re-sync: still exactly one wallet credit.
        $service->run();
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(10800.0, (float) $this->fallback->fresh()->wallet_balance);
    }

    public function test_same_order_different_platform_are_distinct(): void
    {
        AffiliateOrderItem::create([
            'platform'                  => 'Shopee',
            'order_id'                  => 'ORD-030',
            'order_status'              => 'Hoàn thành',
            'checkout_id'               => 'SHOPEE-CHECKOUT-030',
            'shop_name'                 => 'Shopee Shop',
            'shop_id'                   => 0,
            'item_id'                   => 1000,
            'item_name'                 => 'Shopee item',
            'model_id'                  => 0,
            'item_price'                => 100000,
            'quantity'                  => 1,
            'order_amount'              => 100000,
            'commission_type'           => 'Shopee Comm',
            'shopee_commission_rate'    => 0,
            'shopee_commission'         => 0,
            'seller_commission_rate'    => 0,
            'total_product_commission'  => 10000,
            'order_commission_shopee'   => 0,
            'order_commission_seller'   => 0,
            'total_order_commission'    => 10000,
            'agreed_commission_rate'    => 0,
            'net_commission'            => 10000,
            'affiliate_status'          => 'Hoàn thành',
            'import_batch'              => '20260830_000000',
            'user_id'                   => $this->owner->id,
            'username'                  => $this->owner->username,
            'cashback_amount'           => 6000,
            'first_imported_at'         => now(),
            'last_shopee_sync_at'       => now(),
        ]);

        $orders = [$this->settledOrder('ORD-030', 100000, 5000)];

        $result = $this->service($this->mockPageClient($orders))->run();

        $this->assertSame(1, $result->inserted);

        $this->assertSame(1, AffiliateOrderItem::where('platform', 'Shopee')->count());
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'TikTok')->count());
    }

    public function test_date_range_filters_orders_client_side(): void
    {
        $orders = [
            $this->settledOrder('ORD-050', 100000, 18000),
            $this->settledOrder('ORD-051', 100000, 5000),
        ];
        // Move the second order outside the requested window.
        $orders[1]['time_created'] = '2026-08-10 10:00:00';

        $service = $this->service($this->mockPageClient($orders));

        $result = $service->run(from: '2026-07-01', to: '2026-08-01');

        $this->assertSame(2, $result->ordersFetched);
        $this->assertSame(1, $result->inserted);
        $this->assertSame(1, $result->skipped);
    }

    public function test_running_without_range_processes_everything(): void
    {
        $orders = [$this->settledOrder('ORD-060', 100000, 5000)];

        $result = $this->service($this->mockPageClient($orders))->run();

        $this->assertSame(1, $result->ordersFetched);
        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, $result->skipped);
    }

    public function test_insert_stores_content_id_and_tiktok_sync_timestamp(): void
    {
        $order = $this->settledOrder('ORD-070', 100000, 5000);
        $order['content_id'] = '7495366414587628324';

        $service = $this->service($this->mockPageClient([$order]));
        $service->run();

        $item = AffiliateOrderItem::where('platform', 'TikTok')->first();
        $this->assertNotNull($item);
        $this->assertSame('7495366414587628324', $item->content_id);
        $this->assertSame('', $item->checkout_id);
        $this->assertNotNull($item->last_tiktok_sync_at);
        $this->assertNull($item->last_shopee_sync_at);
    }

    public function test_import_without_wallet_credit_writes_rows_only(): void
    {
        $orders = [$this->settledOrder('ORD-080', 100000, 18000)];

        $result = $this->service($this->mockPageClient($orders))->run(creditWallet: false);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, $result->cashbackCredited);

        $item = AffiliateOrderItem::where('platform', 'TikTok')->first();
        $this->assertNotNull($item);
        $this->assertSame(10800.0, (float) $item->cashback_amount);
        $this->assertSame('Hoàn thành', $item->affiliate_status);

        $this->assertSame(0, WalletTransaction::count());
        $this->fallback->refresh();
        $this->assertSame(0.0, (float) $this->fallback->wallet_balance);
    }

    public function test_import_twice_does_not_duplicate_or_change_cashback(): void
    {
        $orders = [$this->settledOrder('ORD-090', 100000, 5000)];

        $service = $this->service($this->mockPageClient($orders));

        $first = $service->run(creditWallet: false);
        $second = $service->run(creditWallet: false);

        $this->assertSame(1, $first->inserted);
        $this->assertSame(0, $first->updated);

        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->updated);

        $this->assertSame(1, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(2500.0, (float) AffiliateOrderItem::where('platform', 'TikTok')->first()->cashback_amount);
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_refund_after_settled_reversals_the_credit(): void
    {
        $settled = $this->settledOrder('ORD-040', 100000, 18000);
        $refunded = [
            'order_id'          => 'ORD-040',
            'product_id'        => (string) (1000 + (int) substr('ORD-040', -3)),
            'product_name'      => 'Sản phẩm ORD-040',
            'status'            => 3,
            'settlement_status' => 'REFUNDED',
            'commission_gmv'    => 100000,
            'actual_commission' => null,
            'time_created'      => '2026-07-28 10:00:00',
        ];

        $current = $settled;

        $client = Mockery::mock(RioHubClient::class);
        $client->shouldReceive('getOrders')
            ->andReturnUsing(function () use (&$current) {
                return new RioHubResponse(200, [
                    'total'     => 1,
                    'page'      => 1,
                    'page_size' => 50,
                    'orders'    => [$current],
                ]);
            });

        $service = $this->service($client);
        $service->run();

        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(10800.0, (float) $this->fallback->fresh()->wallet_balance);

        // Now the same order is refunded on the source.
        $current = $refunded;

        $service->run();

        $item = AffiliateOrderItem::where('platform', 'TikTok')->where('order_id', 'ORD-040')->first();
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame('Đã hủy', $item->affiliate_status);
        $this->assertSame(0.0, (float) $item->cashback_amount);

        // One CREDIT + one REVERSAL (debit) => net wallet unchanged.
        $this->assertSame(2, WalletTransaction::count());

        $credit = WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->first();
        $refund = WalletTransaction::where('type', WalletTransaction::TYPE_REFUND)->first();
        $this->assertNotNull($credit);
        $this->assertNotNull($refund);
        $this->assertSame(WalletTransaction::DIRECTION_CREDIT, $credit->direction);
        $this->assertSame(WalletTransaction::DIRECTION_DEBIT, $refund->direction);
        $this->assertSame(10800.0, (float) $credit->amount);
        $this->assertSame(10800.0, (float) $refund->amount);

        $this->assertSame(0.0, (float) $this->fallback->fresh()->wallet_balance);

        // Re-sync the refund: still exactly one reversal, no double-debit.
        $service->run();
        $this->assertSame(2, WalletTransaction::count());
        $this->assertSame(0.0, (float) $this->fallback->fresh()->wallet_balance);
    }

    public function test_refunded_from_the_start_is_never_credited(): void
    {
        $refunded = [
            'order_id'          => 'ORD-100',
            'product_id'        => '99999',
            'product_name'      => 'Sản phẩm ORD-100',
            'status'            => 3,
            'settlement_status' => 'REFUNDED',
            'commission_gmv'    => 100000,
            'actual_commission' => null,
            'time_created'      => '2026-07-28 10:00:00',
        ];

        $service = $this->service($this->mockPageClient([$refunded]));
        $service->run();

        $item = AffiliateOrderItem::where('platform', 'TikTok')->first();
        $this->assertSame('Đã hủy', $item->affiliate_status);
        $this->assertSame(0.0, (float) $item->cashback_amount);

        $this->assertSame(0, WalletTransaction::count());
        $this->fallback->refresh();
        $this->assertSame(0.0, (float) $this->fallback->wallet_balance);
    }

    public function test_multiple_orders_credit_independently(): void
    {
        $orders = [
            $this->settledOrder('ORD-A1', 100000, 8848),
            $this->settledOrder('ORD-B1', 100000, 1110),
        ];

        $service = $this->service($this->mockPageClient($orders));
        $service->run();

        $items = AffiliateOrderItem::where('platform', 'TikTok')->get();
        $this->assertSame(2, $items->count());

        // 8848 * 0.5 = 4424 ; 1110 * 0.5 = 555
        $this->assertSame(4424.0, (float) $items->where('order_id', 'ORD-A1')->first()->cashback_amount);
        $this->assertSame(555.0, (float) $items->where('order_id', 'ORD-B1')->first()->cashback_amount);

        $this->assertSame(2, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());
        $total = (float) WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->sum('amount');
        $this->assertSame(4979.0, $total);

        $this->fallback->refresh();
        $this->assertSame(4979.0, (float) $this->fallback->wallet_balance);
    }

    public function test_cashback_credited_to_separate_users(): void
    {
        $orders = [
            $this->settledOrder('ORD-C1', 100000, 8848, 0),
            $this->settledOrder('ORD-C2', 100000, 1110, 0),
        ];

        // Give the second order a sub1 that resolves to the owner user.
        $orders[1]['sub1'] = $this->owner->username;

        $service = $this->service($this->mockPageClient($orders));
        $service->run();

        $this->fallback->refresh();
        $this->owner->refresh();

        $this->assertSame(4424.0, (float) $this->fallback->wallet_balance);
        $this->assertSame(555.0, (float) $this->owner->wallet_balance);

        $this->assertSame(2, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());
    }

    public function test_commission_change_after_credit_is_blocked_not_adjusted(): void
    {
        $v1 = $this->settledOrder('ORD-D1', 100000, 8848);
        $v2 = $this->settledOrder('ORD-D1', 100000, 9000);

        $current = $v1;
        $client = Mockery::mock(RioHubClient::class);
        $client->shouldReceive('getOrders')
            ->andReturnUsing(function () use (&$current) {
                return new RioHubResponse(200, [
                    'total'     => 1,
                    'page'      => 1,
                    'page_size' => 50,
                    'orders'    => [$current],
                ]);
            });

        $service = $this->service($client);

        $service->run();
        $this->assertSame(4424.0, (float) $this->fallback->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());

        // Commission changes (8848 -> 9000) on a later sync.
        $current = $v2;
        $service->run();

        // Wallet amount unchanged: no adjustment, no re-credit.
        $this->assertSame(4424.0, (float) $this->fallback->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());
    }

    public function test_wallet_balance_reconciles_with_ledger_after_credit_and_reversal(): void
    {
        $settled = $this->settledOrder('ORD-F1', 100000, 8848);
        $refunded = [
            'order_id'          => 'ORD-F1',
            'product_id'        => $settled['product_id'],
            'product_name'      => 'Sản phẩm ORD-F1',
            'status'            => 3,
            'settlement_status' => 'REFUNDED',
            'commission_gmv'    => 100000,
            'actual_commission' => null,
            'time_created'      => '2026-07-28 10:00:00',
        ];

        $current = $settled;
        $client = Mockery::mock(RioHubClient::class);
        $client->shouldReceive('getOrders')
            ->andReturnUsing(function () use (&$current) {
                return new RioHubResponse(200, [
                    'total'     => 1,
                    'page'      => 1,
                    'page_size' => 50,
                    'orders'    => [$current],
                ]);
            });

        $service = $this->service($client);

        $service->run();
        $current = $refunded;
        $service->run();

        $this->assertSame(2, WalletTransaction::count());

        // Ledger reconciliation must equal user.wallet_balance.
        $this->fallback->refresh();
        $credits = (float) WalletTransaction::where('user_id', $this->fallback->id)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)->sum('amount');
        $debits = (float) WalletTransaction::where('user_id', $this->fallback->id)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)->sum('amount');
        $this->assertSame($credits - $debits, (float) $this->fallback->wallet_balance);
        $this->assertSame(0.0, (float) $this->fallback->wallet_balance);
    }
}
