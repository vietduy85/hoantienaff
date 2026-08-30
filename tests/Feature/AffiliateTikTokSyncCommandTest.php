<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AffiliateTikTokSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $fallback;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.riohub.base_url'         => 'https://riohub.vn/api/v1',
            'services.riohub.api_key'          => 'rhk_TEST_API_KEY_abcdef0123456789',
            'services.riohub.creator_username' => 'hoan_tien_mua_sam',
        ]);

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

    private function riohubOrders(): array
    {
        return [
            [
                'order_id'          => 'ORD-100',
                'product_id'        => '100001',
                'product_name'      => 'Sản phẩm 100001',
                'content_id'        => '7495366414587628324',
                'status'            => 2,
                'settlement_status' => 'SETTLED',
                'commission_gmv'    => 100000,
                'actual_commission' => 18000,
                'est_commission'    => 18000,
                'pit'               => 'MCN-001',
                'sub_id'            => '',
                'sub1'              => '',
                'time_created'      => '2026-07-28 10:00:00',
            ],
            [
                'order_id'          => 'ORD-101',
                'product_id'        => '100002',
                'product_name'      => 'Sản phẩm 100002',
                'content_id'        => '1234567890',
                'status'            => 2,
                'settlement_status' => 'SETTLED',
                'commission_gmv'    => 100000,
                'actual_commission' => 5000,
                'est_commission'    => 5000,
                'pit'               => 'MCN-001',
                'sub_id'            => 'owner_user',
                'sub1'              => '',
                'time_created'      => '2026-07-28 11:00:00',
            ],
            [
                'order_id'          => 'ORD-102',
                'product_id'        => '100003',
                'product_name'      => 'Sản phẩm 100003',
                'content_id'        => '',
                'status'            => 3,
                'settlement_status' => 'REFUNDED',
                'commission_gmv'    => 100000,
                'actual_commission' => null,
                'est_commission'    => 8800,
                'pit'               => null,
                'sub_id'            => '',
                'sub1'              => '',
                'time_created'      => '2026-07-29 09:00:00',
            ],
            [
                'order_id'          => 'ORD-103',
                'product_id'        => '100004',
                'product_name'      => 'Sản phẩm 100004',
                'content_id'        => '5555555555',
                'status'            => 2,
                'settlement_status' => 'SETTLED',
                'commission_gmv'    => 20000,
                'actual_commission' => 12000,
                'est_commission'    => 9000,
                'pit'               => 'MCN-002',
                'sub_id'            => '',
                'sub1'              => 'owner_user',
                'time_created'      => '2026-07-29 10:00:00',
            ],
        ];
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

    public function test_dry_run_reads_api_and_writes_nothing(): void
    {
        $this->fakeRioHubPaged($this->riohubOrders());

        $code = Artisan::call('affiliate:tiktok-sync', ['--dry-run' => true]);
        $this->assertSame(0, $code);

        $output = Artisan::output();

        $this->assertStringContainsString('ORD-100', $output);
        $this->assertStringContainsString('ORD-102', $output);
        $this->assertStringContainsString('7495366414587628324', $output);
        $this->assertStringContainsString('tintuctonghop103', $output);
        $this->assertStringContainsString('owner_user', $output);
        $this->assertStringContainsString('60%', $output);
        $this->assertStringContainsString('50%', $output);
        $this->assertStringContainsString('10,800', $output);
        $this->assertStringContainsString('2,500', $output);
        $this->assertStringContainsString('MATCH', $output);
        $this->assertStringContainsString('DIFFERENT', $output);
        $this->assertStringContainsString('NO (dry-run)', $output);

        $this->assertSame(0, AffiliateOrderItem::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0.0, (float) User::sum('wallet_balance'));
        $this->assertSame(0.0, (float) User::sum('total_earned'));
    }

    public function test_dry_run_reports_mapping_stats(): void
    {
        $this->fakeRioHubPaged($this->riohubOrders());

        $code = Artisan::call('affiliate:tiktok-sync', ['--dry-run' => true]);
        $this->assertSame(0, $code);

        $output = Artisan::output();

        $this->assertStringContainsString('USER MAPPING', $output);
        $this->assertStringContainsString('Matched by sub_id', $output);
        $this->assertStringContainsString('Matched by sub1', $output);
        $this->assertStringContainsString('Fallback — sub_id & sub1 rỗng', $output);
        $this->assertStringContainsString('Fallback — không tìm thấy user', $output);
        $this->assertStringContainsString('Unresolved (user_id NULL)', $output);
    }

    public function test_dry_run_fetches_all_pages(): void
    {
        $this->fakeRioHubPaged($this->riohubOrders());

        $code = Artisan::call('affiliate:tiktok-sync', ['--dry-run' => true, '--page-size' => 2]);
        $this->assertSame(0, $code);

        Http::assertSentCount(2);

        $output = Artisan::output();

        $this->assertStringContainsString('Pages fetched', $output);
        $this->assertStringContainsString('Orders fetched', $output);
        $this->assertStringContainsString('ORD-103', $output);
    }

    public function test_without_dry_run_flag_is_blocked(): void
    {
        $this->fakeRioHubPaged($this->riohubOrders());

        $code = Artisan::call('affiliate:tiktok-sync');
        $this->assertSame(1, $code);

        $output = Artisan::output();

        $this->assertStringContainsString('[BLOCK]', $output);
        Http::assertNothingSent();

        $this->assertSame(0, AffiliateOrderItem::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_api_error_stops_without_writing(): void
    {
        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => Http::response(['message' => 'Internal error'], 500),
        ]);

        $code = Artisan::call('affiliate:tiktok-sync', ['--dry-run' => true]);
        $this->assertSame(1, $code);

        $output = Artisan::output();

        $this->assertStringContainsString('API ERROR', $output);
        $this->assertStringContainsString('HTTP status', $output);
        $this->assertStringContainsString('500', $output);

        $this->assertSame(0, AffiliateOrderItem::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0.0, (float) User::sum('wallet_balance'));
        $this->assertSame(0.0, (float) User::sum('total_earned'));
    }

    public function test_import_writes_rows_without_wallet_credit(): void
    {
        $this->fakeRioHubPaged($this->riohubOrders());

        $code = Artisan::call('affiliate:tiktok-sync', ['--import' => true]);
        $this->assertSame(0, $code);

        $this->assertSame(4, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(0, AffiliateOrderItem::where('platform', 'Shopee')->count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0.0, (float) User::sum('wallet_balance'));
        $this->assertSame(0.0, (float) User::sum('total_earned'));

        $output = Artisan::output();

        $this->assertStringContainsString('INSERTED', $output);
        $this->assertStringContainsString('ORD-100', $output);
        $this->assertStringContainsString('ORD-103', $output);
        $this->assertStringContainsString('7495366414587628324', $output);
        $this->assertStringContainsString('tintuctonghop103', $output);
        $this->assertStringContainsString('owner_user', $output);
        $this->assertStringContainsString('(empty)', $output);
        $this->assertStringContainsString('0 (chưa gọi WalletService)', $output);
        $this->assertStringContainsString('KẾT LUẬN: wallet + Shopee KHÔNG đổi ✓', $output);
    }

    public function test_import_twice_does_not_duplicate(): void
    {
        $this->fakeRioHubPaged($this->riohubOrders());

        $first = Artisan::call('affiliate:tiktok-sync', ['--import' => true]);
        $this->assertSame(0, $first);

        $second = Artisan::call('affiliate:tiktok-sync', ['--import' => true]);
        $this->assertSame(0, $second);

        $this->assertSame(4, AffiliateOrderItem::where('platform', 'TikTok')->count());
        $this->assertSame(0, WalletTransaction::count());

        $output = Artisan::output();

        $this->assertStringContainsString('INSERTED', $output);
        $this->assertStringContainsString('UPDATED', $output);
        $this->assertStringContainsString('Duplicate groups', $output);
        $this->assertStringContainsString('0 (chưa gọi WalletService)', $output);
    }

    public function test_import_with_both_flags_is_blocked(): void
    {
        $this->fakeRioHubPaged($this->riohubOrders());

        $code = Artisan::call('affiliate:tiktok-sync', ['--dry-run' => true, '--import' => true]);
        $this->assertSame(1, $code);

        $this->assertStringContainsString('[BLOCK]', Artisan::output());
        $this->assertSame(0, AffiliateOrderItem::count());
        $this->assertSame(0, WalletTransaction::count());
    }
}