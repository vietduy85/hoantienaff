<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Lazada\LazadaApiClient;
use App\Services\Lazada\LazadaFinalizeService;
use App\Services\Lazada\LazadaOrderSyncService;
use App\Services\Lazada\LazadaSyncResult;
use App\Services\ShopeeFood\ShopeeFoodOrderSyncService;
use App\Services\ShopeeFood\ShopeeFoodSyncResult;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokSyncResult;
use App\Services\WalletService;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Fixture\LazadaConversionFixture;
use Tests\TestCase;

/**
 * `affiliate:sync-all` orchestrator + Windows Task Scheduler contract.
 *
 *  [1/4] TikTok Sync → [2/4] Lazada Sync → [3/4] Lazada Finalize (ONLY after a
 *  fully successful Lazada Sync) → [4/4] ShopeeFood Sync.
 *
 * Windows Task Scheduler runs `C:\xampp\php\php.exe artisan affiliate:sync-all`
 * every 6 hours (XML: C:\Users\Administrator\Downloads\WindowScheduleSync.xml).
 * Laravel Scheduler (schedule:run / withSchedule) is intentionally NOT used.
 */
class AffiliateSyncAllTest extends TestCase
{
    use RefreshDatabase;

    private const XML_PATH = 'C:\Users\Administrator\Downloads\WindowScheduleSync.xml';

    private const DELIVERED = '2026-08-04 09:00:00';

    private const XML_NS = 'http://schemas.microsoft.com/windows/2004/02/mit/task';

    /** Records served on Lazada API page 1 (mutable so repeated syncs see new data). */
    private array $page1Records = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeLazadaCredentials();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.lazada.vn/*' => function (HttpRequest $request) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                $records = $page === 1
                    ? $this->page1Records
                    : [];

                return Http::response($this->payload($records));
            },
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Log::clearResolvedInstance();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Mocks / helpers
    // ------------------------------------------------------------------

    /**
     * @return array{checked: int, eligible: int, finalized: int, credited: int, skipped: int, historical_protected: int, reversed: int, errors: int}
     */
    private function finalizeCounts(array $overrides = []): array
    {
        return array_merge([
            'checked'              => 0,
            'eligible'             => 0,
            'finalized'            => 0,
            'credited'             => 0,
            'skipped'              => 0,
            'historical_protected' => 0,
            'reversed'             => 0,
            'errors'               => 0,
        ], $overrides);
    }

    private function okTikTok(?array &$calls = null, int $times = 1): Mockery\MockInterface
    {
        $mock = Mockery::mock(TikTokOrderSyncService::class);
        $mock->shouldReceive('run')->times($times)->with(null, null, [], null, true)
            ->andReturnUsing(function () use (&$calls): TikTokSyncResult {
                if ($calls !== null) {
                    $calls[] = 'tikTokSync';
                }

                return new TikTokSyncResult();
            });

        return $mock;
    }

    private function okShopeeFood(?array &$calls = null, int $times = 1): Mockery\MockInterface
    {
        $mock = Mockery::mock(ShopeeFoodOrderSyncService::class);
        $mock->shouldReceive('run')->times($times)->with(null, null, true, true)
            ->andReturnUsing(function () use (&$calls): ShopeeFoodSyncResult {
                if ($calls !== null) {
                    $calls[] = 'shopeeFoodSync';
                }

                return new ShopeeFoodSyncResult();
            });

        return $mock;
    }

    private function swapLog(): Mockery\LegacyMockInterface
    {
        $log = Mockery::spy();
        Log::swap($log);

        return $log;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function assertLogHas(Mockery\LegacyMockInterface $log, string $level, array $needles): void
    {
        $log->shouldHaveReceived($level, [
            Mockery::on(fn (string $message): bool => collect($needles)->contains(
                fn (string $needle): bool => str_contains($message, $needle),
            )),
            Mockery::any(),
        ]);
    }

    private function bindAll(
        Mockery\MockInterface $tikTok,
        Mockery\MockInterface $lazadaSync,
        Mockery\MockInterface $finalizer,
        Mockery\MockInterface $shopeeFood,
    ): void {
        $this->app->instance(TikTokOrderSyncService::class, $tikTok);
        $this->app->instance(LazadaOrderSyncService::class, $lazadaSync);
        $this->app->instance(LazadaFinalizeService::class, $finalizer);
        $this->app->instance(ShopeeFoodOrderSyncService::class, $shopeeFood);
    }

    private function fakeLazadaCredentials(): void
    {
        config([
            'services.lazada.app_key'    => 'FAKE_APP_KEY',
            'services.lazada.app_secret' => 'FAKE_APP_SECRET_OF_32_CHARS___',
            'services.lazada.user_token' => 'FAKE_USER_TOKEN',
            'services.lazada.base_url'   => 'https://api.lazada.vn/rest',
        ]);
    }

    private function payload(array $records): array
    {
        return [
            'code'       => 0,
            'request_id' => 'req-test',
            '_trace_id_' => 'trace-test',
            'result'     => [
                'success' => true,
                'data'    => $records,
            ],
        ];
    }

    private function fakePage1(array $overrides = []): void
    {
        $this->page1Records = [array_merge(LazadaConversionFixture::base(), $overrides)];
    }

    private function createMember(): User
    {
        return User::factory()->create([
            'username'       => 'alice123',
            'wallet_balance' => 0,
            'total_earned'   => 0,
        ]);
    }

    private function memberOverrides(User $member, string $status): array
    {
        return [
            'status' => $status,
            'subId1' => (string) $member->id,
            'subId2' => 'alice123',
        ];
    }

    private function freeze(string $now): void
    {
        Carbon::setTestNow(Carbon::parse($now));
    }

    // ------------------------------------------------------------------
    //  Command existence + happy path + ordering
    // ------------------------------------------------------------------

    public function test_command_exists(): void
    {
        $this->assertArrayHasKey('affiliate:sync-all', Artisan::all());
    }

    public function test_successful_run_calls_tiktok_then_lazada_sync_then_finalize_then_shopeefood_and_exits_zero(): void
    {
        $calls = [];

        $tikTok = $this->okTikTok($calls);
        $shopeeFood = $this->okShopeeFood($calls);

        $lazadaSync = Mockery::mock(LazadaOrderSyncService::class);
        $lazadaSync->shouldReceive('run')->once()->with(null, null, true, true)
            ->andReturnUsing(function () use (&$calls): LazadaSyncResult {
                $calls[] = 'lazadaSync';

                return new LazadaSyncResult();
            });

        $finalizer = Mockery::mock(LazadaFinalizeService::class);
        $finalizer->shouldReceive('run')->once()->with(Mockery::type(WalletService::class))
            ->andReturnUsing(function () use (&$calls): array {
                $calls[] = 'lazadaFinalize';

                return $this->finalizeCounts();
            });

        $this->bindAll($tikTok, $lazadaSync, $finalizer, $shopeeFood);

        $this->artisan('affiliate:sync-all')->assertExitCode(0);

        $this->assertSame(
            ['tikTokSync', 'lazadaSync', 'lazadaFinalize', 'shopeeFoodSync'],
            $calls,
            'finalize must run directly AFTER a successful Lazada Sync',
        );
    }

    // ------------------------------------------------------------------
    //  Lazada failure ⇒ finalize is skipped + non-zero exit
    // ------------------------------------------------------------------

    public function test_lazada_sync_exception_skips_finalize_and_exits_non_zero(): void
    {
        $log = $this->swapLog();

        $tikTok = $this->okTikTok();
        $shopeeFood = $this->okShopeeFood();

        $lazadaSync = Mockery::mock(LazadaOrderSyncService::class);
        $lazadaSync->shouldReceive('run')->once()->with(null, null, true, true)
            ->andThrow(new \RuntimeException('Lazada API boom'));

        $finalizer = Mockery::mock(LazadaFinalizeService::class);
        $finalizer->shouldNotReceive('run');

        $this->bindAll($tikTok, $lazadaSync, $finalizer, $shopeeFood);

        $this->artisan('affiliate:sync-all')->assertExitCode(1);

        $this->assertLogHas($log, 'error', ['Lazada Sync failed']);
        $this->assertLogHas($log, 'warning', ['Lazada Finalize skipped']);
        $this->assertLogHas($log, 'error', ['Affiliate Sync All completed with errors']);
    }

    public function test_lazada_sync_reporting_errors_skips_finalize_and_exits_non_zero(): void
    {
        $log = $this->swapLog();

        $tikTok = $this->okTikTok();
        $shopeeFood = $this->okShopeeFood();

        $lazadaSync = Mockery::mock(LazadaOrderSyncService::class);
        $lazadaSync->shouldReceive('run')->once()->with(null, null, true, true)
            ->andReturn(new LazadaSyncResult(errors: 1));

        $finalizer = Mockery::mock(LazadaFinalizeService::class);
        $finalizer->shouldNotReceive('run');

        $this->bindAll($tikTok, $lazadaSync, $finalizer, $shopeeFood);

        $this->artisan('affiliate:sync-all')->assertExitCode(1);

        $this->assertLogHas($log, 'warning', ['Lazada Sync completed with errors']);
        $this->assertLogHas($log, 'warning', ['Lazada Finalize skipped']);
    }

    public function test_tiktok_exception_is_isolated_from_the_other_feeds(): void
    {
        $log = $this->swapLog();

        $calls = [];

        $tikTok = Mockery::mock(TikTokOrderSyncService::class);
        $tikTok->shouldReceive('run')->once()->with(null, null, [], null, true)
            ->andThrow(new \RuntimeException('TikTok API timeout'));

        $lazadaSync = Mockery::mock(LazadaOrderSyncService::class);
        $lazadaSync->shouldReceive('run')->once()->with(null, null, true, true)
            ->andReturnUsing(function () use (&$calls): LazadaSyncResult {
                $calls[] = 'lazadaSync';

                return new LazadaSyncResult();
            });

        $finalizer = Mockery::mock(LazadaFinalizeService::class);
        $finalizer->shouldReceive('run')->once()->with(Mockery::type(WalletService::class))
            ->andReturnUsing(function () use (&$calls): array {
                $calls[] = 'lazadaFinalize';

                return $this->finalizeCounts();
            });

        $shopeeFood = $this->okShopeeFood($calls);

        $this->bindAll($tikTok, $lazadaSync, $finalizer, $shopeeFood);

        $this->artisan('affiliate:sync-all')->assertExitCode(1);

        $this->assertLogHas($log, 'error', ['TikTok Sync failed']);

        // Failure is isolated: the remaining three steps still run, in order.
        $this->assertSame(['lazadaSync', 'lazadaFinalize', 'shopeeFoodSync'], $calls);
    }

    // ------------------------------------------------------------------
    //  Orchestrator lock (no overlapping runs)
    // ------------------------------------------------------------------

    public function test_running_while_another_run_holds_the_lock_is_blocked(): void
    {
        $tikTok = Mockery::mock(TikTokOrderSyncService::class)->shouldNotReceive('run')->getMock();
        $lazadaSync = Mockery::mock(LazadaOrderSyncService::class)->shouldNotReceive('run')->getMock();
        $finalizer = Mockery::mock(LazadaFinalizeService::class)->shouldNotReceive('run')->getMock();
        $shopeeFood = Mockery::mock(ShopeeFoodOrderSyncService::class)->shouldNotReceive('run')->getMock();

        $this->bindAll($tikTok, $lazadaSync, $finalizer, $shopeeFood);

        $lock = Cache::lock('affiliate:sync-all:lock', 7200);
        $lock->get();

        try {
            $this->artisan('affiliate:sync-all')->assertExitCode(1);
        } finally {
            $lock->forceRelease();
        }
    }

    // ------------------------------------------------------------------
    //  Real-feed integration: no duplicate wallet credits; history safe
    // ------------------------------------------------------------------

    /**
     * Real Lazada sync + real finalizer via the orchestrator, run twice.
     * Proves the aggregator does not introduce duplicate rows / WalletTransactions.
     */
    public function test_orchestrator_never_duplicates_wallet_transactions_across_runs(): void
    {
        $member = $this->createMember();

        $this->freeze('2026-08-15 09:00:00');            // delivered + 11d → eligible
        $this->fakePage1($this->memberOverrides($member, 'fulfilled'));

        $this->app->instance(LazadaOrderSyncService::class, new LazadaOrderSyncService(new LazadaApiClient()));
        $this->app->instance(LazadaFinalizeService::class, app(LazadaFinalizeService::class));

        $this->app->instance(TikTokOrderSyncService::class, $this->okTikTok(times: 2));
        $this->app->instance(ShopeeFoodOrderSyncService::class, $this->okShopeeFood(times: 2));

        $this->artisan('affiliate:sync-all')->assertExitCode(0);
        $this->artisan('affiliate:sync-all')->assertExitCode(0);

        $this->assertSame(1, AffiliateOrderItem::where('platform', 'Lazada')->count());
        $this->assertSame(1, WalletTransaction::where('type', 'cashback')->count(), 'one and only one cashback credit');

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertNotNull($row->finalized_at, 'eligible row must be finalized on first run');
        $this->assertSame(6000.0, (float) $row->cashback_amount);
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_orchestrator_never_moves_historical_credited_rows(): void
    {
        $member = $this->createMember();

        // Historical Lazada row credited BEFORE the lifecycle (finalized_at NULL
        // on purpose — protection marker is the completed wallet credit).
        $item = AffiliateOrderItem::factory()->create([
            'platform'          => 'Lazada',
            'order_id'          => '839912345678901',
            'lazada_line_key'   => '839912345678901|839912345678902|6021831634002',
            'lazada_raw_status' => 'fulfilled',
            'delivered_at'      => Carbon::parse(self::DELIVERED),
            'finalized_at'      => null,
            'affiliate_status'  => AffiliateOrderItem::STATUS_COMPLETED,
            'order_status'      => AffiliateOrderItem::STATUS_COMPLETED,
            'cashback_rate'     => 0.50,
            'cashback_amount'   => 5000,
            'net_commission'    => 12000,
            'order_amount'      => 200000,
            'user_id'           => $member->id,
            'username'          => $member->username,
        ]);

        WalletTransaction::factory()->create([
            'user_id'        => $member->id,
            'username'       => $member->username,
            'platform'       => 'Lazada',
            'type'           => WalletTransaction::TYPE_CASHBACK,
            'direction'      => 'credit',
            'amount'         => 5000,
            'reference_type' => 'affiliate_order_item',
            'reference_id'   => $item->id,
            'status'         => WalletTransaction::STATUS_COMPLETED,
            'completed_at'   => Carbon::parse('2026-07-01 00:00:00'),
        ]);

        $this->freeze('2026-09-01 00:00:00'); // far past the 10-day window

        $this->app->instance(LazadaOrderSyncService::class, new LazadaOrderSyncService(new LazadaApiClient()));
        $this->app->instance(LazadaFinalizeService::class, app(LazadaFinalizeService::class));

        $this->app->instance(TikTokOrderSyncService::class, $this->okTikTok());
        $this->app->instance(ShopeeFoodOrderSyncService::class, $this->okShopeeFood());

        $this->artisan('affiliate:sync-all')->assertExitCode(0);

        $item->refresh();
        $this->assertNull($item->finalized_at, 'historical row must NOT be finalized');
        $this->assertSame(AffiliateOrderItem::STATUS_COMPLETED, $item->affiliate_status);
        $this->assertSame(5000.0, (float) $item->cashback_amount);
        $this->assertSame(1, WalletTransaction::count(), 'no new transaction for the historical row');
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  Laravel Scheduler is NOT involved
    // ------------------------------------------------------------------

    public function test_no_laravel_schedule_registers_lazada_finalize_or_sync_all(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringNotContainsString('affiliate:lazada-finalize', $output);
        $this->assertStringNotContainsString('affiliate:sync-all', $output);
        $this->assertStringNotContainsString('03:30', $output);
    }

    // ------------------------------------------------------------------
    //  Windows Task Scheduler XML (C:\Users\Administrator\Downloads)
    // ------------------------------------------------------------------

    public function test_windows_task_xml_exists(): void
    {
        $this->assertFileExists(self::XML_PATH);
    }

    public function test_windows_task_xml_is_valid_xml(): void
    {
        $doc = new DOMDocument();
        $loaded = $doc->load(self::XML_PATH);

        $this->assertTrue($loaded, 'XML must parse');
        $this->assertSame('Task', $doc->documentElement->nodeName);
    }

    public function test_windows_task_xml_has_program_arguments_and_start_in(): void
    {
        $doc = new DOMDocument();
        $doc->load(self::XML_PATH);

        $command = $this->xmlText($doc, 'Command');
        $arguments = $this->xmlText($doc, 'Arguments');
        $workingDir = $this->xmlText($doc, 'WorkingDirectory');

        $this->assertSame('C:\xampp\php\php.exe', $command);
        $this->assertStringContainsString('artisan affiliate:sync-all', $arguments);
        $this->assertSame('C:\xampp\htdocs\hoantienaff', $workingDir);
    }

    public function test_windows_task_xml_repeats_every_six_hours_indefinitely(): void
    {
        $doc = new DOMDocument();
        $doc->load(self::XML_PATH);

        $this->assertSame('PT6H', $this->xmlText($doc, 'Interval'));
        $this->assertSame('P1D', $this->xmlText($doc, 'Duration'));
        $this->assertSame('false', $this->xmlText($doc, 'StopAtDurationEnd'));
        $this->assertSame('1', $this->xmlText($doc, 'DaysInterval'));
    }

    public function test_windows_task_xml_ignores_new_instances(): void
    {
        $doc = new DOMDocument();
        $doc->load(self::XML_PATH);

        $this->assertSame('IgnoreNew', $this->xmlText($doc, 'MultipleInstancesPolicy'));
    }

    private function xmlText(DOMDocument $doc, string $tag): string
    {
        $node = $doc->getElementsByTagNameNS(self::XML_NS, $tag)->item(0);
        $this->assertNotNull($node, "XML node {$tag} must exist");

        return $node->textContent;
    }
}