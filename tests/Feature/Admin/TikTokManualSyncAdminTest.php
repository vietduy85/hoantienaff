<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TikTokManualSyncAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $fallback;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.riohub.base_url'         => 'https://riohub.vn/api/v1',
            'services.riohub.api_key'          => 'rhk_TEST_API_KEY_abcdef0123456789',
            'services.riohub.creator_username' => 'hoan_tien_mua_sam',
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'Admin']);

        $this->fallback = User::factory()->create([
            'username'       => 'tintuctonghop103',
            'wallet_balance' => 0,
            'total_earned'   => 0,
        ]);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');
    }

    private function fakeRioHubPaged(array $orders): void
    {
        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => function (Request $request) use ($orders) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                $page  = (int) ($query['page'] ?? 1);
                $size  = (int) ($query['page_size'] ?? 50);
                $chunk = array_slice($orders, ($page - 1) * $size, $size);

                return Http::response([
                    'page'      => $page,
                    'page_size' => $size,
                    'total'     => count($orders),
                    'orders'    => $chunk,
                ], 200);
            },
        ]);
    }

    public function test_get_page_does_not_call_riohub_and_does_not_change_db(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertOk()
            ->assertSee('Đồng bộ đơn hàng TikTok')
            ->assertSee('Lần đồng bộ TikTok gần nhất');

        Http::assertNothingSent();
        $this->assertSame(0, AffiliateOrderItem::count());
    }

    public function test_get_page_shows_last_tiktok_sync_at(): void
    {
        AffiliateOrderItem::create([
            'platform'                   => 'TikTok',
            'order_id'                   => 'T-1',
            'order_status'               => 'Hoàn thành',
            'checkout_id'                => '',
            'content_id'                 => '7495366414587628324',
            'shop_name'                  => '',
            'shop_id'                    => 0,
            'item_id'                    => 1,
            'item_name'                  => 'i',
            'model_id'                   => 0,
            'item_price'                 => 100,
            'quantity'                   => 1,
            'order_amount'               => 100,
            'refund_amount'              => 0,
            'commission_type'            => 'Fixed',
            'shopee_commission_rate'     => 0,
            'shopee_commission'          => 0,
            'seller_commission_rate'     => 0,
            'xtra_commission'            => 0,
            'total_product_commission'   => 10,
            'order_commission_shopee'    => 0,
            'order_commission_seller'    => 0,
            'total_order_commission'     => 10,
            'mcn_management_fee_rate'    => 0,
            'mcn_management_fee'         => 0,
            'agreed_commission_rate'     => 50,
            'net_commission'             => 10,
            'affiliate_status'           => 'Hoàn thành',
            'import_batch'               => '20260830_000000',
            'user_id'                    => $this->fallback->id,
            'username'                   => $this->fallback->username,
            'cashback_amount'            => 5,
            'first_imported_at'          => now(),
            'last_tiktok_sync_at'        => '2026-08-30 12:00:00',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertOk()
            ->assertSee('30/08/2026 12:00:00');
    }

    public function test_post_sync_flashes_structured_result(): void
    {
        $this->fakeRioHubPaged([
            [
                'order_id'          => 'ORD-300',
                'product_id'        => '300001',
                'product_name'      => 'Sản phẩm 300001',
                'content_id'        => '7495366414587628324',
                'status'            => 2,
                'settlement_status' => 'SETTLED',
                'commission_gmv'    => 100000,
                'actual_commission' => 8848,
                'est_commission'    => 8848,
                'pit'               => 'MCN-001',
                'sub_id'            => '',
                'sub1'              => '',
                'time_created'      => '2026-07-28 10:00:00',
            ],
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect(route('admin.tiktok-order-sync.index'))
            ->assertSessionHas('tiktok_sync_result', function (array $res) {
                $this->assertTrue((bool) $res['success']);
                $this->assertSame('Đồng bộ TikTok hoàn tất', $res['message']);
                $this->assertSame(1, (int) $res['orders_fetched']);
                $this->assertSame(1, (int) $res['items_fetched']);
                $this->assertSame(1, (int) $res['inserted']);
                $this->assertSame(0, (int) $res['errors']);
                $this->assertSame(1, (int) $res['wallet_credits']);
                $this->assertArrayHasKey('duration', $res);

                return true;
            });
    }
}
