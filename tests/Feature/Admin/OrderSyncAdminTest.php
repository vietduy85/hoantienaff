<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StubsAddLiveTagApi;
use Tests\TestCase;

class OrderSyncAdminTest extends TestCase
{
    use RefreshDatabase;
    use StubsAddLiveTagApi;

    private User $admin;

    private User $fallback;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.riohub.base_url' => 'https://riohub.vn/api/v1',
            'services.riohub.api_key' => 'rhk_TEST_API_KEY_abcdef0123456789',
            'services.riohub.creator_username' => 'hoan_tien_mua_sam',
            'services.shopeefood.base_url' => 'https://data.addlivetag.com/shopeefood',
            'services.shopeefood.cookie' => 'TEST_SPF_COOKIE',
        ]);

        // Phase 3: the manual sync button also runs the shared Shopee/AddLiveTag
        // pipeline — stub the conversions API so tests never hit the real one.
        $this->stubAddLiveTagApi();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'Admin']);

        $this->fallback = User::factory()->create([
            'username' => 'tintuctonghop103',
            'wallet_balance' => 0,
            'total_earned' => 0,
        ]);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');

        $this->member = User::factory()->create(['username' => 'member']);
    }

    private function fakeRioHubPaged(array $orders): void
    {
        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => function (Request $request) use ($orders) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                $page = (int) ($query['page'] ?? 1);
                $size = (int) ($query['page_size'] ?? 50);
                $chunk = array_slice($orders, ($page - 1) * $size, $size);

                return Http::response([
                    'page' => $page,
                    'page_size' => $size,
                    'total' => count($orders),
                    'orders' => $chunk,
                ], 200);
            },
        ]);
    }

    private function fakeShopeeFood(array $checkouts): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'total_count' => count($checkouts),
                    'page_size' => 100,
                    'list' => $checkouts,
                ],
            ]),
        ]);
    }

    private function shopeeCheckout(string $id, string $username): array
    {
        return [
            'checkout_id' => $id,
            'conversion_status' => 2,
            'is_shopee_capped' => false,
            'checkout_cap' => 0,
            'capped_commission' => 0,
            'affiliate_net_commission' => '50000000', // 500 VND
            'utm_content' => $username.'----',
            'orders' => [
                [
                    'order_sn' => '',
                    'items' => [
                        [
                            'promotion_id' => '0_0_111',
                            'item_id' => 1,
                            'item_name' => 'Com suon',
                            'shop_name' => 'Cua Hang',
                            'actual_amount' => 100000000, // 1000 VND
                            'platform_commission_rate' => 5000, // 5%
                            'item_commission' => 50000000, // 500 VND
                            'affiliate_item_status' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function shopeeApiRow(array $overrides = []): array
    {
        return array_merge([
            'order_sn' => '261008AAA900',
            'checkout_id' => 'CO-900',
            'purchase_time' => '2026-10-08 08:00:00',
            'complete_time' => '2026-10-08 09:00:00',
            'click_time' => '2026-10-07 09:00:00',
            'item_url' => 'https://addlivetag.com/product/?item_id=999888777',
            'item_name' => 'Phase 3 product',
            'price' => 100000,
            'qty' => 1,
            'order_value' => 100000,
            'commission' => 10000,
            'mcn_fee' => 0,
            'sub_id1' => 'member',
            'status_code' => 'completed',
            'commission_status' => 'Đã chốt',
        ], $overrides);
    }

    private function makeShopeeRow(array $overrides = []): AffiliateOrderItem
    {
        return AffiliateOrderItem::create(array_merge([
            'order_id' => '261008AAA901',
            'order_status' => 'Hoàn thành',
            'checkout_id' => 'CO-901',
            'ordered_at' => now(),
            'item_id' => '999888001',
            'item_name' => 'Phase 3 product',
            'shop_name' => '',
            'shop_id' => 0,
            'model_id' => 0,
            'commission_type' => '',
            'shopee_commission_rate' => 0,
            'shopee_commission' => 0,
            'seller_commission_rate' => 0,
            'xtra_commission' => 0,
            'order_commission_shopee' => 0,
            'order_commission_seller' => 0,
            'agreed_commission_rate' => 0,
            'refund_amount' => 0,
            'item_price' => 100000,
            'quantity' => 1,
            'order_amount' => 100000,
            'total_product_commission' => 10000,
            'mcn_management_fee' => 0,
            'net_commission' => 10000,
            'total_order_commission' => 10000,
            'affiliate_status' => 'Hoàn thành',
            'sub_id1' => 'member',
            'platform' => 'Shopee',
            'user_id' => $this->member->id,
            'username' => $this->member->username,
            'cashback_rate' => 50,
            'cashback_amount' => 4500,
            'import_batch' => 'csv_batch',
            'source_file' => 'AffiliateCommissionReport.csv',
        ], $overrides));
    }

    public function test_get_index_shows_unified_sync_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertOk()
            ->assertSee('Đồng bộ đơn hàng TikTok, Lazada, ShopeeFood & Shopee')
            ->assertSee('Lần đồng bộ TikTok gần nhất')
            ->assertSee('Lần đồng bộ ShopeeFood gần nhất')
            ->assertSee('Lần đồng bộ Shopee gần nhất');
    }

    public function test_get_index_shows_last_shopee_sync_at(): void
    {
        $this->makeShopeeRow(['last_shopee_sync_at' => '2026-10-08 09:30:00']);

        $this->actingAs($this->admin)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertOk()
            ->assertSee('Lần đồng bộ Shopee gần nhất')
            ->assertSee('08/10/2026 09:30:00');
    }

    public function test_get_index_does_not_call_any_api_and_does_not_change_db(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, AffiliateOrderItem::count());
    }

    public function test_post_runs_both_feeds_and_flashes_separate_results(): void
    {
        $this->fakeRioHubPaged([
            [
                'order_id' => 'ORD-300',
                'product_id' => '300001',
                'product_name' => 'Sản phẩm 300001',
                'content_id' => '7495366414587628324',
                'status' => 2,
                'settlement_status' => 'SETTLED',
                'tt_order_status' => 103,
                'commission_gmv' => 100000,
                'actual_commission' => 8848,
                'est_commission' => 8848,
                'pit' => 'MCN-001',
                'sub_id' => '',
                'sub1' => '',
                'time_created' => '2026-07-28 10:00:00',
            ],
        ]);

        $this->fakeShopeeFood([$this->shopeeCheckout('SPF-1', 'tintuctonghop103')]);

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'), [
                'from' => '2026-07-01',
                'to' => '2026-08-31',
            ])
            ->assertRedirect(route('admin.tiktok-order-sync.index'))
            ->assertSessionHas('tiktok_sync_result', function (array $res) {
                return (bool) $res['success']
                    && (int) $res['orders_fetched'] === 1
                    && (int) $res['inserted'] === 1;
            })
            ->assertSessionHas('shopeefood_sync_result', function (array $res) {
                return (bool) $res['success']
                    && (int) $res['checkouts_fetched'] === 1
                    && (int) $res['items_fetched'] === 1
                    && (int) $res['inserted'] === 1
                    && (int) $res['wallet_credits'] === 1;
            })
            ->assertSessionHas('shopee_sync_result', function (array $res) {
                return (bool) $res['success']
                    && $res['api'] === 'AddLiveTag'
                    && (int) $res['raw_items'] === 0;
            });

        // TikTok ran real persist + wallet credit.
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'TikTok')->first()->user_id ? 1 : 0);

        // ShopeeFood ran REAL persist + wallet credit too (Phase 2):
        // 1 TikTok cashback + 1 ShopeeFood cashback.
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'ShopeeFood')->count());
        $this->assertSame(2, WalletTransaction::count());
    }

    public function test_post_runs_shopee_addlivetag_step_and_flashes_result(): void
    {
        // Phase 3: the same button also runs affiliate:sync-all step 5
        // (shared ShopeeApiSyncService, 14-day window, apply + wallet credit).
        $this->stubAddLiveTagApi([$this->shopeeApiRow()]);

        $this->fakeRioHubPaged([]);
        $this->fakeShopeeFood([]);

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect(route('admin.tiktok-order-sync.index'))
            ->assertSessionHas('shopee_sync_result', function (array $res) {
                return (bool) $res['success']
                    && $res['api'] === 'AddLiveTag'
                    && (int) $res['raw_items'] === 1
                    && (int) $res['orders'] === 1
                    && (int) $res['inserted'] === 1
                    && (int) $res['updated'] === 0
                    && (int) $res['protected'] === 0
                    && (int) $res['wallet_credits'] === 1
                    && (int) $res['wallet_errors'] === 0;
            });

        $row = AffiliateOrderItem::where('platform', 'Shopee')->firstOrFail();
        $this->assertSame($this->member->id, $row->user_id);
        $this->assertGreaterThan(0, (float) $row->cashback_amount);
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame((float) $row->cashback_amount, (float) $this->member->fresh()->wallet_balance);

        // No API key / secret leaks into the flash payload.
        $summary = session('shopee_sync_result');
        $this->assertStringNotContainsString('api_key', json_encode($summary));
    }

    public function test_post_shopee_api_failure_is_reported_as_failure_not_success(): void
    {
        // Truncated pagination → incomplete dataset must FAIL, never fake success.
        $this->stubTruncatedShopeeApi();

        $this->fakeRioHubPaged([]);
        $this->fakeShopeeFood([]);

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect(route('admin.tiktok-order-sync.index'))
            ->assertSessionHas('shopee_sync_error', fn (string $msg) => str_contains($msg, 'Pagination truncated'));

        $this->assertSame(0, AffiliateOrderItem::where('platform', 'Shopee')->count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_shopeefood_config_missing_keeps_tiktok_result(): void
    {
        config(['services.shopeefood.cookie' => null]);

        $this->fakeRioHubPaged([
            [
                'order_id' => 'ORD-301',
                'product_id' => '301001',
                'product_name' => 'Sản phẩm 301001',
                'status' => 2,
                'settlement_status' => 'SETTLED',
                'commission_gmv' => 100000,
                'actual_commission' => 5000,
                'est_commission' => 5000,
                'time_created' => '2026-07-28 10:00:00',
            ],
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertSessionHas('tiktok_sync_result')
            ->assertSessionHas('shopeefood_sync_error', fn (string $msg) => str_contains($msg, 'SHOPEEFOOD_COOKIE'));
    }

    public function test_tiktok_failure_keeps_shopeefood_result(): void
    {
        Http::fake([
            // RioHub errors out.
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => Http::response('server error', 500),
        ]);

        $this->fakeShopeeFood([$this->shopeeCheckout('SPF-2', 'tintuctonghop103')]);

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertSessionHas('tiktok_sync_error')
            ->assertSessionHas('shopeefood_sync_result', function (array $res) {
                return (int) $res['checkouts_fetched'] === 1;
            });
    }

    public function test_validation_rejects_invalid_date_range(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'), ['from' => 'not-a-date'])
            ->assertSessionHasErrors('from');
    }

    public function test_member_without_admin_role_is_forbidden(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertForbidden();
    }
}
