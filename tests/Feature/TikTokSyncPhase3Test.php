<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TikTokSyncPhase3Test extends TestCase
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

    private function settledOrder(string $orderId, float $gmv, float $actualCommission, string $sub1 = ''): array
    {
        return [
            'order_id'          => $orderId,
            'product_id'        => (string) (1000 + (int) substr($orderId, -3)),
            'product_name'      => "Sản phẩm {$orderId}",
            'content_id'        => '7495366414587628324',
            'status'            => 2,
            'settlement_status' => 'SETTLED',
            'commission_gmv'    => $gmv,
            'actual_commission' => $actualCommission,
            'est_commission'    => $actualCommission,
            'pit'               => 'MCN-001',
            'sub_id'            => '',
            'sub1'              => $sub1,
            'time_created'      => '2026-07-28 10:00:00',
        ];
    }

    public function test_laravel_scheduler_does_not_register_tiktok_auto_sync(): void
    {
        // Laravel Scheduler is NOT used for TikTok auto-sync. Frequency is owned
        // by Windows Task Scheduler (php artisan affiliate:tiktok-sync --sync).
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringNotContainsString('affiliate:tiktok-sync', $output);
    }

    public function test_sync_command_inserts_and_credits_wallet(): void
    {
        $this->fakeRioHubPaged([$this->settledOrder('ORD-200', 100000, 8848)]);

        $code = Artisan::call('affiliate:tiktok-sync', ['--sync' => true]);
        $this->assertSame(0, $code);

        $this->assertSame(1, AffiliateOrderItem::where('platform', 'TikTok')->count());

        $item = AffiliateOrderItem::where('platform', 'TikTok')->first();
        $this->assertSame(4424.0, (float) $item->cashback_amount);
        $this->assertSame('Hoàn thành', $item->affiliate_status);

        $this->assertSame(1, WalletTransaction::where('type', 'cashback')->count());
        $this->fallback->refresh();
        $this->assertSame(4424.0, (float) $this->fallback->wallet_balance);

        $output = Artisan::output();
        $this->assertStringContainsString('Wallet credits', $output);
        $this->assertStringContainsString('INSERTED', $output);
    }

    public function test_sync_command_is_idempotent_on_rerun(): void
    {
        $this->fakeRioHubPaged([$this->settledOrder('ORD-201', 100000, 8848)]);

        $first  = Artisan::call('affiliate:tiktok-sync', ['--sync' => true]);
        $second = Artisan::call('affiliate:tiktok-sync', ['--sync' => true]);

        $this->assertSame(0, $first);
        $this->assertSame(0, $second);

        // Single row, single credit, wallet unchanged by second run.
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(1, WalletTransaction::where('type', 'cashback')->count());
        $this->fallback->refresh();
        $this->assertSame(4424.0, (float) $this->fallback->wallet_balance);
    }

    public function test_sync_command_processes_all_pages(): void
    {
        $orders = [
            $this->settledOrder('ORD-210', 100000, 8848),
            $this->settledOrder('ORD-211', 100000, 555),
            $this->settledOrder('ORD-212', 100000, 1110),
        ];

        $this->fakeRioHubPaged($orders);

        $code = Artisan::call('affiliate:tiktok-sync', ['--sync' => true, '--page-size' => 2]);
        $this->assertSame(0, $code);

        $this->assertSame(3, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(3, WalletTransaction::where('type', 'cashback')->count());
        $this->fallback->refresh();
        $this->assertSame(5256.0, (float) $this->fallback->wallet_balance);
    }

    public function test_sync_command_skips_when_lock_is_held(): void
    {
        $lock = Cache::lock('affiliate-tiktok-sync:lock', 1800);
        $lock->get();

        $this->fakeRioHubPaged([$this->settledOrder('ORD-220', 100000, 8848)]);

        $code = Artisan::call('affiliate:tiktok-sync', ['--sync' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Một phiên đồng bộ', Artisan::output());

        $this->assertSame(0, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(0, WalletTransaction::count());

        $lock->release();
    }

    public function test_sync_command_fails_safely_on_api_timeout(): void
    {
        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('timed out');
            },
        ]);

        $code = Artisan::call('affiliate:tiktok-sync', ['--sync' => true]);

        $this->assertSame(1, $code);
        // Fail-safe: no wipe, no blanket reverse, nothing harmful.
        $this->assertSame(0, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(0, WalletTransaction::count());
        $this->fallback->refresh();
        $this->assertSame(0.0, (float) $this->fallback->wallet_balance);
    }

    public function test_sync_command_fails_fast_on_401(): void
    {
        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $code = Artisan::call('affiliate:tiktok-sync', ['--sync' => true]);
        $this->assertSame(1, $code);
        $this->assertSame(0, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_admin_manual_sync_is_blocked_when_lock_is_held(): void
    {
        Cache::lock('affiliate-tiktok-sync:lock', 1800)->get();

        $this->fakeRioHubPaged([$this->settledOrder('ORD-230', 100000, 8848)]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect(route('admin.tiktok-order-sync.index'));

        $response->assertSessionHas('tiktok_sync_error');

        $this->assertSame(0, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_admin_manual_sync_credits_and_updates_tiktok_timestamp_only(): void
    {
        // Pre-existing Shopee row to prove it is untouched.
        $shopee = AffiliateOrderItem::create([
            'platform'             => 'Shopee',
            'order_id'             => 'S-001',
            'order_status'         => 'Hoàn thành',
            'checkout_id'          => 'SC-1',
            'shop_name'            => 'S',
            'shop_id'              => 0,
            'item_id'              => 1,
            'item_name'            => 'i',
            'model_id'             => 0,
            'item_price'           => 100,
            'quantity'             => 1,
            'order_amount'         => 100,
            'commission_type'      => 'Fixed',
            'shopee_commission_rate' => 5,
            'shopee_commission'    => 10,
            'seller_commission_rate' => 0,
            'total_product_commission' => 10,
            'order_commission_shopee' => 10,
            'order_commission_seller' => 0,
            'total_order_commission' => 10,
            'agreed_commission_rate' => 5,
            'net_commission'       => 10,
            'affiliate_status'     => 'Hoàn thành',
            'import_batch'         => '20260830_000000',
            'user_id'              => $this->fallback->id,
            'username'             => $this->fallback->username,
            'cashback_amount'      => 5,
            'first_imported_at'    => now(),
            'last_shopee_sync_at'  => now()->subDay(),
        ]);

        $this->fakeRioHubPaged([$this->settledOrder('ORD-240', 100000, 8848)]);

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect();

        $tiktok = AffiliateOrderItem::where('platform', 'TikTok')->first();
        $this->assertNotNull($tiktok);
        $this->assertNotNull($tiktok->last_tiktok_sync_at);

        $shopee->refresh();
        // TikTok sync must NOT touch Shopee row or its last_shopee_sync_at.
        $this->assertNotNull($shopee->last_shopee_sync_at);
        $this->assertNull($shopee->last_tiktok_sync_at);
        $this->assertSame(5.0, (float) $shopee->cashback_amount);
    }

    public function test_preexisting_credited_order_is_not_credited_again_by_admin(): void
    {
        // Seed an already-settled + credited order using the same sync path.
        $this->fakeRioHubPaged([$this->settledOrder('ORD-250', 100000, 8848)]);
        Artisan::call('affiliate:tiktok-sync', ['--sync' => true]);
        $this->assertSame(1, WalletTransaction::where('type', 'cashback')->count());

        // Admin re-sync: must not double credit.
        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect();

        $this->assertSame(1, WalletTransaction::where('type', 'cashback')->count());
        $this->fallback->refresh();
        $this->assertSame(4424.0, (float) $this->fallback->wallet_balance);
    }
}
