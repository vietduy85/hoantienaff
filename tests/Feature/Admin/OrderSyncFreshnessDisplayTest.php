<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaFinalizeService;
use App\Services\Lazada\LazadaOrderSyncService;
use App\Services\ShopeeFood\ShopeeFoodOrderSyncService;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Support\AffiliateOrderUpdateStatus;
use App\Support\AffiliateSyncAttempt;
use App\Support\AffiliateSyncLock;
use App\Support\AffiliateSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StubsAddLiveTagApi;
use Tests\Fixture\LazadaConversionFixture;
use Tests\TestCase;

/**
 * Admin order-sync freshness monitor: "Đơn cập nhật gần nhất" per platform,
 * "Lần thử đồng bộ gần nhất" (AffiliateSyncAttempt) and the 24h warnings.
 */
class OrderSyncFreshnessDisplayTest extends TestCase
{
    use RefreshDatabase;
    use StubsAddLiveTagApi;

    private const NOW = '2026-10-15 12:00:00';

    private User $admin;

    private array $tikTokOrders = [];

    private array $lazadaRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.riohub.base_url' => 'https://riohub.vn/api/v1',
            'services.riohub.api_key' => 'rhk_TEST_API_KEY_abcdef0123456789',
            'services.riohub.creator_username' => 'hoan_tien_mua_sam',
            'services.shopeefood.base_url' => 'https://data.addlivetag.com/shopeefood',
            'services.shopeefood.cookie' => 'TEST_SPF_COOKIE',
            'services.lazada.app_key' => 'FAKE_APP_KEY',
            'services.lazada.app_secret' => 'FAKE_APP_SECRET_OF_32_CHARS___',
            'services.lazada.user_token' => 'FAKE_USER_TOKEN',
            'services.lazada.base_url' => 'https://api.lazada.vn/rest',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');

        User::factory()->create(['username' => 'tintuctonghop103', 'wallet_balance' => 0, 'total_earned' => 0]);

        $this->stubAddLiveTagApi();

        Http::preventStrayRequests();
        $this->fakeRioHub();
        $this->fakeLazada();
        $this->fakeShopeeFood();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Feeds / fixtures
    // ------------------------------------------------------------------

    private function tikTokOrder(string $orderId, float $actualCommission): array
    {
        return [
            'order_id' => $orderId,
            'product_id' => '777001',
            'product_name' => 'Sản phẩm test',
            'content_id' => '7495366414587628324',
            'status' => 2,
            'settlement_status' => 'SETTLED',
            'tt_order_status' => 103,
            'commission_gmv' => 100000,
            'actual_commission' => $actualCommission,
            'est_commission' => $actualCommission,
            'sub_id' => '',
            'sub1' => '',
            'time_created' => '2026-07-28 10:00:00',
        ];
    }

    private function fakeRioHub(): void
    {
        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => function (Request $request) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                $page = (int) ($query['page'] ?? 1);
                $size = (int) ($query['page_size'] ?? 50);
                $chunk = $page === 1 ? $this->tikTokOrders : [];

                return Http::response([
                    'page' => $page,
                    'page_size' => $size,
                    'total' => count($this->tikTokOrders),
                    'orders' => array_slice($chunk, 0, $size),
                ], 200);
            },
        ]);
    }

    private function fakeLazada(): void
    {
        Http::fake([
            'https://api.lazada.vn/*' => function (Request $request) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                return Http::response([
                    'code' => 0,
                    'request_id' => 'req-test',
                    '_trace_id_' => 'trace-test',
                    'result' => [
                        'success' => true,
                        'data' => $page === 1 ? $this->lazadaRecords : [],
                    ],
                ]);
            },
        ]);
    }

    private function fakeShopeeFood(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'code' => 0,
                'msg' => 'success',
                'data' => ['total_count' => 0, 'page_size' => 100, 'list' => []],
            ]),
        ]);
    }

    private function index(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.tiktok-order-sync.index'));
    }

    // ------------------------------------------------------------------
    //  "Đơn cập nhật gần nhất" per platform
    // ------------------------------------------------------------------

    public function test_tiktok_order_update_time_uses_latest_tiktok_row_timestamp(): void
    {
        AffiliateOrderItem::factory()->create([
            'platform' => 'TikTok',
            'last_tiktok_sync_at' => '2026-10-14 08:00:00',
        ]);
        AffiliateOrderItem::factory()->create([
            'platform' => 'TikTok',
            'last_tiktok_sync_at' => '2026-10-15 09:30:00',
        ]);

        $response = $this->index()->assertOk();

        $this->assertMatchesRegularExpression(
            '/data-testid="tiktok-order-update-time">15\/10\/2026 09:30:00/',
            $response->getContent(),
        );
    }

    public function test_lazada_order_update_time_uses_latest_lazada_row_timestamp(): void
    {
        AffiliateOrderItem::factory()->create([
            'platform' => 'Lazada',
            'last_lazada_sync_at' => '2026-10-15 07:15:00',
        ]);

        $response = $this->index()->assertOk();

        $this->assertMatchesRegularExpression(
            '/data-testid="lazada-order-update-time">15\/10\/2026 07:15:00/',
            $response->getContent(),
        );
    }

    public function test_shopee_order_update_time_ignores_csv_import_rows(): void
    {
        AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'source_file' => 'AffiliateCommissionReport.csv',
            'last_shopee_sync_at' => '2026-10-15 11:00:00',
        ]);
        AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'source_file' => 'addlivetag-api',
            'last_shopee_sync_at' => '2026-10-14 11:00:00',
        ]);

        $content = $this->index()->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/Lần cập nhật đơn Shopee từ LiveTag gần nhất:<\/span>\s*<span class="font-mono">14\/10\/2026 11:00:00/',
            $content,
        );
    }

    public function test_order_update_time_shows_chua_co_without_rows(): void
    {
        $content = $this->index()->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-testid="tiktok-order-update-time">Chưa có/',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/data-testid="lazada-order-update-time">Chưa có/',
            $content,
        );
    }

    // ------------------------------------------------------------------
    //  24h warnings
    // ------------------------------------------------------------------

    public function test_stale_tiktok_shows_warning_banner(): void
    {
        Carbon::setTestNow(self::NOW);

        AffiliateOrderItem::factory()->create([
            'platform' => 'TikTok',
            'last_tiktok_sync_at' => '2026-10-13 11:00:00',
        ]);
        AffiliateSyncStatus::record(AffiliateSyncStatus::TIKTOK, Carbon::parse('2026-10-13 11:00:00'));

        $this->index()
            ->assertOk()
            ->assertSee('data-testid="tiktok-stale-warning"', false)
            ->assertSee(AffiliateOrderUpdateStatus::TIKTOK_MESSAGE);
    }

    public function test_stale_lazada_shows_warning_banner(): void
    {
        Carbon::setTestNow(self::NOW);

        AffiliateOrderItem::factory()->create([
            'platform' => 'Lazada',
            'last_lazada_sync_at' => '2026-10-13 11:00:00',
        ]);

        $this->index()
            ->assertOk()
            ->assertSee('data-testid="lazada-stale-warning"', false)
            ->assertSee(AffiliateOrderUpdateStatus::LAZADA_MESSAGE);
    }

    public function test_fresh_tiktok_does_not_warn(): void
    {
        Carbon::setTestNow(self::NOW);

        AffiliateOrderItem::factory()->create([
            'platform' => 'TikTok',
            'last_tiktok_sync_at' => '2026-10-15 11:00:00',
        ]);

        $this->index()
            ->assertOk()
            ->assertDontSee('data-testid="tiktok-stale-warning"', false);
    }

    public function test_shopee_stale_warning_banner_still_renders(): void
    {
        Carbon::setTestNow(self::NOW);

        AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'source_file' => 'addlivetag-api',
            'last_shopee_sync_at' => '2026-10-13 11:00:00',
        ]);

        $this->index()
            ->assertOk()
            ->assertSee('data-testid="shopee-stale-warning"', false)
            ->assertSee(AffiliateOrderUpdateStatus::SHOPEE_MESSAGE);
    }

    public function test_never_updated_without_sync_evidence_does_not_warn(): void
    {
        Carbon::setTestNow(self::NOW);

        $this->index()
            ->assertOk()
            ->assertDontSee('data-testid="tiktok-stale-warning"', false)
            ->assertDontSee('data-testid="lazada-stale-warning"', false)
            ->assertDontSee('data-testid="shopee-stale-warning"', false);
    }

    public function test_never_updated_with_sync_evidence_warns(): void
    {
        Carbon::setTestNow(self::NOW);

        AffiliateSyncStatus::record(AffiliateSyncStatus::LAZADA, Carbon::parse('2026-10-15 10:00:00'));
        AffiliateSyncAttempt::record(AffiliateSyncAttempt::LAZADA, AffiliateSyncAttempt::STATE_SUCCESS, 'success');

        $this->index()
            ->assertOk()
            ->assertSee('data-testid="lazada-stale-warning"', false)
            ->assertSee(AffiliateOrderUpdateStatus::LAZADA_MESSAGE)
            ->assertSee('Chưa từng có');
    }

    // ------------------------------------------------------------------
    //  "Lần thử đồng bộ gần nhất"
    // ------------------------------------------------------------------

    public function test_attempt_marker_renders_state_and_time_for_tiktok(): void
    {
        Carbon::setTestNow(self::NOW);

        AffiliateOrderItem::factory()->create([
            'platform' => 'TikTok',
            'last_tiktok_sync_at' => '2026-10-15 11:00:00',
        ]);

        AffiliateSyncAttempt::record(
            AffiliateSyncAttempt::TIKTOK,
            AffiliateSyncAttempt::STATE_SKIPPED,
            'lock_busy',
            Carbon::parse('2026-10-15 09:00:00'),
        );

        $this->index()
            ->assertOk()
            ->assertSee('Lần thử đồng bộ gần nhất')
            ->assertSee('15/10/2026 09:00:00')
            ->assertSee('Bị bỏ qua');
    }

    // ------------------------------------------------------------------
    //  POST / command attempt side effects
    // ------------------------------------------------------------------

    public function test_post_records_success_attempts_for_all_platforms(): void
    {
        $this->tikTokOrders = [$this->tikTokOrder('ORD-FRESH-1', 5000)];

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect(route('admin.tiktok-order-sync.index'));

        foreach ([AffiliateSyncAttempt::TIKTOK, AffiliateSyncAttempt::LAZADA, AffiliateSyncAttempt::SHOPEE] as $platform) {
            $attempt = AffiliateSyncAttempt::last($platform);

            $this->assertNotNull($attempt, "no attempt recorded for {$platform}");
            $this->assertSame(AffiliateSyncAttempt::STATE_SUCCESS, $attempt['state'], $platform);
        }
    }

    public function test_post_lazada_api_error_records_failed_attempt(): void
    {
        $this->tikTokOrders = [];

        $lazada = Mockery::mock(LazadaOrderSyncService::class);
        $lazada->shouldReceive('run')->andThrow(new LazadaException('no credentials'));
        $this->app->instance(LazadaOrderSyncService::class, $lazada);

        $this->actingAs($this->admin)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect(route('admin.tiktok-order-sync.index'));

        $attempt = AffiliateSyncAttempt::last(AffiliateSyncAttempt::LAZADA);

        $this->assertNotNull($attempt);
        $this->assertSame(AffiliateSyncAttempt::STATE_FAILED, $attempt['state']);
        $this->assertSame('api_error', $attempt['reason']);
    }

    public function test_post_lock_busy_records_skipped_attempts_for_all_platforms(): void
    {
        $lock = Cache::lock(AffiliateSyncLock::KEY, AffiliateSyncLock::SECONDS);
        $lock->get();

        try {
            $this->actingAs($this->admin)
                ->post(route('admin.tiktok-order-sync.sync'))
                ->assertRedirect(route('admin.tiktok-order-sync.index'));
        } finally {
            $lock->forceRelease();
        }

        foreach ([AffiliateSyncAttempt::TIKTOK, AffiliateSyncAttempt::LAZADA, AffiliateSyncAttempt::SHOPEE] as $platform) {
            $attempt = AffiliateSyncAttempt::last($platform);

            $this->assertNotNull($attempt, "no attempt recorded for {$platform}");
            $this->assertSame(AffiliateSyncAttempt::STATE_SKIPPED, $attempt['state'], $platform);
            $this->assertSame('lock_busy', $attempt['reason'], $platform);
        }
    }

    public function test_sync_all_lock_timeout_records_skipped_attempts(): void
    {
        $this->app->instance(
            TikTokOrderSyncService::class,
            Mockery::mock(TikTokOrderSyncService::class)->shouldNotReceive('run')->getMock(),
        );
        $this->app->instance(
            LazadaOrderSyncService::class,
            Mockery::mock(LazadaOrderSyncService::class)->shouldNotReceive('run')->getMock(),
        );
        $this->app->instance(
            LazadaFinalizeService::class,
            Mockery::mock(LazadaFinalizeService::class)->shouldNotReceive('run')->getMock(),
        );
        $this->app->instance(
            ShopeeFoodOrderSyncService::class,
            Mockery::mock(ShopeeFoodOrderSyncService::class)->shouldNotReceive('run')->getMock(),
        );

        $lock = Cache::lock(AffiliateSyncLock::KEY, AffiliateSyncLock::SECONDS);
        $lock->get();

        try {
            $this->artisan('affiliate:sync-all', ['--lock-wait' => 0])->assertExitCode(1);
        } finally {
            $lock->forceRelease();
        }

        foreach ([AffiliateSyncAttempt::TIKTOK, AffiliateSyncAttempt::LAZADA, AffiliateSyncAttempt::SHOPEE] as $platform) {
            $attempt = AffiliateSyncAttempt::last($platform);

            $this->assertNotNull($attempt, "no attempt recorded for {$platform}");
            $this->assertSame(AffiliateSyncAttempt::STATE_SKIPPED, $attempt['state'], $platform);
            $this->assertSame('lock_timeout', $attempt['reason'], $platform);
        }
    }

    // ------------------------------------------------------------------
    //  Anti-drift: re-sync without content change must NOT look fresh
    // ------------------------------------------------------------------

    public function test_tiktok_resync_without_content_change_keeps_order_update_timestamp(): void
    {
        $this->tikTokOrders = [$this->tikTokOrder('ORD-DRIFT-1', 18000)];

        $service = $this->app->make(TikTokOrderSyncService::class);

        Carbon::setTestNow('2026-10-15 09:00:00');
        $service->run(null, null, [], null, false);

        Carbon::setTestNow('2026-10-15 12:00:00');
        $service->run(null, null, [], null, false);

        $row = AffiliateOrderItem::where('platform', 'TikTok')->where('order_id', 'ORD-DRIFT-1')->firstOrFail();

        $this->assertSame('2026-10-15 09:00:00', $row->last_tiktok_sync_at->format('Y-m-d H:i:s'));
    }

    public function test_tiktok_resync_with_changed_commission_advances_order_update_timestamp(): void
    {
        $this->tikTokOrders = [$this->tikTokOrder('ORD-DRIFT-2', 18000)];

        $service = $this->app->make(TikTokOrderSyncService::class);

        Carbon::setTestNow('2026-10-15 09:00:00');
        $service->run(null, null, [], null, false);

        $this->tikTokOrders = [$this->tikTokOrder('ORD-DRIFT-2', 25000)];

        Carbon::setTestNow('2026-10-15 12:00:00');
        $service->run(null, null, [], null, false);

        $row = AffiliateOrderItem::where('platform', 'TikTok')->where('order_id', 'ORD-DRIFT-2')->firstOrFail();

        $this->assertSame('2026-10-15 12:00:00', $row->last_tiktok_sync_at->format('Y-m-d H:i:s'));
    }

    public function test_lazada_resync_without_content_change_keeps_order_update_timestamp(): void
    {
        $this->lazadaRecords = [LazadaConversionFixture::base()];

        $service = $this->app->make(LazadaOrderSyncService::class);

        Carbon::setTestNow('2026-10-15 09:00:00');
        $service->run(null, null, true, false);

        Carbon::setTestNow('2026-10-15 12:00:00');
        $service->run(null, null, true, false);

        $row = AffiliateOrderItem::where('platform', 'Lazada')->firstOrFail();

        $this->assertSame('2026-10-15 09:00:00', $row->last_lazada_sync_at->format('Y-m-d H:i:s'));
    }
}
