<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Lazada\LazadaApiClient;
use App\Services\Lazada\LazadaOrderSyncService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\LazadaConversionFixture;
use Tests\TestCase;

/**
 * Phase 3 LAZADA FINALIZATION + 10-DAY LOCK contract (tests A–Q).
 *
 * Lifecycle under test:
 *   - sync persists delivered_at = RAW deliveredTime + a pending ESTIMATE and
 *     NEVER credits (finalization is owned by affiliate:lazada-finalize)
 *   - the finalizer credits + LOCKs (finalized_at / finalize_gate /
 *     final_cashback_amount) once now >= delivered_at + 10 days
 *   - historical rows (credited before the lifecycle) are never touched
 *   - finalized rows are TRUE-LOCKED (no downgrade / no overwrite), but a
 *     genuine returned/rejected/cancelled signal reverses exactly once
 *   - insufficient balance aborts a reversal atomically (no refund row)
 *   - the finalizer never touches TikTok / Shopee / ShopeeFood rows
 */
class LazadaFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private const DELIVERED = '2026-08-04 09:00:00';

    /** Records served on API page 1 (mutable so repeated syncs see new data). */
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
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function fakeLazadaCredentials(): void
    {
        config([
            'services.lazada.app_key'    => 'FAKE_APP_KEY',
            'services.lazada.app_secret' => 'FAKE_APP_SECRET_OF_32_CHARS___',
            'services.lazada.user_token' => 'FAKE_USER_TOKEN',
            'services.lazada.base_url'   => 'https://api.lazada.vn/rest',
        ]);
    }

    private function makeService(): LazadaOrderSyncService
    {
        return new LazadaOrderSyncService(new LazadaApiClient());
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

    /**
     * Point the API at one conversion record built from the fixture + overrides
     * (later pages are empty). Mutable, so later calls replace the data.
     */
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

    private function sync(): \App\Services\Lazada\LazadaSyncResult
    {
        return $this->makeService()->run(persist: true, creditWallet: true);
    }

    private function finalize(): void
    {
        $this->artisan('affiliate:lazada-finalize')->assertExitCode(0);
    }

    private function credits(): int
    {
        return WalletTransaction::where('reference_type', 'affiliate_order_item')
            ->where('type', WalletTransaction::TYPE_CASHBACK)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->count();
    }

    private function refunds(): int
    {
        return WalletTransaction::where('reference_type', 'affiliate_order_item')
            ->where('type', WalletTransaction::TYPE_REFUND)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->count();
    }

    private function walletTx(): int
    {
        return WalletTransaction::count();
    }

    /**
     * Sync one delivered/fulfilled order (pending estimate) so the finalizer
     * has something to process.
     */
    private function syncDelivered(User $member): void
    {
        $this->freeze(self::DELIVERED);
        $this->fakePage1($this->memberOverrides($member, 'fulfilled'));
        $this->sync();
    }

    // ------------------------------------------------------------------
    //  A: fulfilled / delivered are pending estimates — sync never credits
    // ------------------------------------------------------------------

    public function test_a_fulfilled_and_delivered_store_pending_estimate_never_credit(): void
    {
        $member = $this->createMember();
        $this->freeze('2026-08-10 00:00:00');
        $this->fakePage1($this->memberOverrides($member, 'fulfilled'));

        $result = $this->sync();

        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, $result->cashbackCredited, 'sync must NEVER credit');
        $this->assertSame(1, $result->cashbackSkipped);
        $this->assertSame(0, $this->walletTx());
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Đang xử lý', $row->affiliate_status);
        $this->assertSame('Đang xử lý', $row->order_status);
        $this->assertSame(6000.0, (float) $row->cashback_amount, 'estimate 12000 @ 50%');
        $this->assertSame(0.50, (float) $row->cashback_rate);
        $this->assertSame(self::DELIVERED, $row->delivered_at->format('Y-m-d H:i:s'));
        $this->assertSame('fulfilled', $row->lazada_raw_status);
        $this->assertNull($row->completed_at);
        $this->assertNull($row->finalized_at);
        $this->assertNull($row->locked_at);
        $this->assertNull($row->final_cashback_amount);

        // Re-sync with a raw delivered status: still pending, still no money.
        $this->fakePage1($this->memberOverrides($member, 'delivered'));
        $second = $this->sync();

        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->updated);
        $this->assertSame(0, $second->cashbackCredited);
        $this->assertSame(1, $second->cashbackSkipped);
        $this->assertSame(0, $this->walletTx());
    }

    // ------------------------------------------------------------------
    //  B / L: deliveredTime is the ONLY anchor — never fall back
    // ------------------------------------------------------------------

    public function test_b_delivered_time_null_means_never_finalized_no_fallback(): void
    {
        $member = $this->createMember();
        $this->freeze('2026-08-20 00:00:00'); // far past any threshold
        $this->fakePage1(array_merge(
            $this->memberOverrides($member, 'fulfilled'),
            ['deliveredTime' => null], // fulfilledTime still present -> must NOT be used
        ));

        $this->sync();

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertNull($row->delivered_at, 'delivered_at must come from deliveredTime ONLY');
        $this->assertNull($row->completed_at);

        $this->finalize();

        $row->refresh();
        $this->assertNull($row->finalized_at, 'NULL delivered_at -> never finalized even past 10d window');
        $this->assertSame('Đang xử lý', $row->affiliate_status);
        $this->assertSame(0, $this->walletTx());
    }

    public function test_l_raw_delivered_time_only_signal_survives_resync(): void
    {
        $member = $this->createMember();
        $this->freeze('2026-08-10 00:00:00');
        $this->fakePage1(array_merge(
            $this->memberOverrides($member, 'fulfilled'),
            ['deliveredTime' => null],
        ));
        $this->sync();
        $this->assertNull(AffiliateOrderItem::where('platform', 'Lazada')->first()->delivered_at);

        $this->fakePage1(array_merge(
            $this->memberOverrides($member, 'fulfilled'),
            ['deliveredTime' => '2026-08-05 12:00:00'],
        ));
        $this->sync();

        $this->assertSame(
            '2026-08-05 12:00:00',
            AffiliateOrderItem::where('platform', 'Lazada')->first()->delivered_at->format('Y-m-d H:i:s'),
        );
    }

    // ------------------------------------------------------------------
    //  C1/C2/C3 + O: the exact 10-day window
    // ------------------------------------------------------------------

    public function test_c1_not_eligible_before_10_days_stays_pending(): void
    {
        $member = $this->createMember();
        $this->freeze('2026-08-13 00:00:00'); // delivered + 8d 15h
        $this->fakePage1($this->memberOverrides($member, 'fulfilled'));
        $this->sync();

        $result = $this->artisan('affiliate:lazada-finalize')
            ->expectsOutputToContain('Rows checked')
            ->run();

        $this->assertSame(0, $result);
        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertNull($row->finalized_at);
        $this->assertSame('Đang xử lý', $row->affiliate_status);
        $this->assertSame(0, $this->walletTx());
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_c2_eligible_exactly_at_10_days_finalizes_and_credits(): void
    {
        $member = $this->createMember();
        $this->syncDelivered($member);
        $this->freeze('2026-08-14 09:00:00'); // exactly delivered + 10 days

        $this->finalize();

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Hoàn thành', $row->affiliate_status);
        $this->assertSame('Hoàn thành', $row->order_status);
        $this->assertSame(6000.0, (float) $row->cashback_amount);
        $this->assertSame(0.50, (float) $row->cashback_rate);
        $this->assertSame('2026-08-14 09:00:00', $row->finalized_at->format('Y-m-d H:i:s'));
        $this->assertSame(AffiliateOrderItem::FINALIZE_GATE_LAZADA_DELIVERED_10D, $row->finalize_gate);
        $this->assertSame(6000.0, (float) $row->final_cashback_amount);
        $this->assertSame('2026-08-14 09:00:00', $row->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-14 09:00:00', $row->locked_at->format('Y-m-d H:i:s'));

        $this->assertSame(1, $this->credits());
        $credit = WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->first();
        $this->assertSame(6000.0, (float) $credit->amount);
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_c3_eligible_after_11_days_finalizes(): void
    {
        $member = $this->createMember();
        $this->syncDelivered($member);
        $this->freeze('2026-08-15 00:00:00'); // delivered + 10d 15h

        $this->finalize();

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertNotNull($row->finalized_at);
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_o_day_boundary_before_and_at_threshold(): void
    {
        $member = $this->createMember();
        $this->syncDelivered($member);

        // delivered (08-04 09:00:00) + 9d 23h 59m 59s -> NOT eligible yet.
        $this->freeze('2026-08-14 08:59:59');
        $this->finalize();
        $this->assertNull(AffiliateOrderItem::where('platform', 'Lazada')->first()->finalized_at);
        $this->assertSame(0, $this->walletTx());

        // delivered + 10 days exactly (08-14 09:00:00) -> eligible.
        $this->freeze('2026-08-14 09:00:00');
        $this->finalize();

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertNotNull($row->finalized_at);
        $this->assertSame('2026-08-14 09:00:00', $row->finalized_at->format('Y-m-d H:i:s'));
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  D: returned / rejected / cancelled never pay
    // ------------------------------------------------------------------

    public function test_d_returned_rejected_cancelled_stored_cancelled_zero_never_credited(): void
    {
        $member = $this->createMember();
        $this->freeze('2026-08-14 09:00:00');

        $i = 0;
        foreach (['returned', 'rejected', 'cancelled'] as $status) {
            // Each status needs its own orderId or the second/third record
            // would collide on the same (orderId:subOrderId:sku) line key.
            $this->fakePage1(array_merge(
                $this->memberOverrides($member, $status),
                ['orderId' => '8399123456789' . (++$i)],
            ));
            $result = $this->sync();

            $this->assertSame(1, $result->inserted);
            $this->assertSame(0, $result->cashbackCredited);
            $this->assertSame(0, $this->walletTx());

            $this->assertTrue(
                AffiliateOrderItem::where('platform', 'Lazada')
                    ->where('lazada_raw_status', $status)
                    ->where('affiliate_status', 'Đã hủy')
                    ->where('cashback_amount', 0.0)
                    ->exists(),
                "{$status} must be stored as Đã hủy with cashback 0",
            );
        }

        $this->finalize();
        $this->assertSame(0, $this->walletTx(), 'finalizer must not pay cancelled rows');
    }

    // ------------------------------------------------------------------
    //  E: unknown statuses are pending estimates — never finalized
    // ------------------------------------------------------------------

    public function test_e_unknown_status_pending_estimate_never_finalized(): void
    {
        $member = $this->createMember();
        $this->freeze('2026-08-20 00:00:00'); // far past 10d
        $this->fakePage1($this->memberOverrides($member, 'powdered-confirmed')); // unknown

        $result = $this->sync();

        $this->assertSame(1, $result->unknownStatuses);
        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('powdered-confirmed', $row->lazada_raw_status);
        $this->assertSame('Đang xử lý', $row->affiliate_status);
        $this->assertSame(6000.0, (float) $row->cashback_amount, 'estimate shown, wallet untouched');

        $this->finalize();

        $row->refresh();
        $this->assertNull($row->finalized_at, 'unknown raw status must never be finalized');
        $this->assertSame(0, $this->walletTx());
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  F/G: historical protection (money already moved) — never touched
    // ------------------------------------------------------------------

    private function createHistoricalCreditedRow(User $member, string $rawStatus = 'fulfilled'): AffiliateOrderItem
    {
        $row = AffiliateOrderItem::create(array_merge($this->lazadaBaseRow(), [
            'user_id'              => $member->id,
            'username'             => $member->username,
            'affiliate_status'     => 'Hoàn thành',
            'order_status'         => 'Hoàn thành',
            'cashback_amount'      => 6000.00,
            'cashback_rate'        => 0.50,
            'delivered_at'         => '2026-08-04 09:00:00',
            'lazada_raw_status'    => $rawStatus,
        ]));

        (new WalletService())->creditCashback($row);

        return $row;
    }

    public function test_f_historical_credited_row_immune_to_sync_and_finalizer(): void
    {
        $member = $this->createMember();
        $this->freeze('2026-08-14 09:00:00');
        $this->createHistoricalCreditedRow($member);

        // Sync a "newer" fulfilled version: must be protected, not updated.
        $this->fakePage1($this->memberOverrides($member, 'fulfilled'));
        $result = $this->sync();

        $this->assertSame(1, $result->protectedSkipped);
        $this->assertSame(0, $result->updated);
        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Hoàn thành', $row->affiliate_status, 'must not be downgraded by sync');
        $this->assertSame(6000.0, (float) $row->cashback_amount);
        $this->assertSame(1, $this->credits());

        // Finalizer must skip the historical row too (no double credit).
        $this->finalize();
        $this->assertSame(1, $this->credits());
        $this->assertSame(1, $this->walletTx());
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
        $this->assertNull($row->fresh()->finalized_at);
    }

    public function test_g_historical_credited_row_never_auto_reversed_on_returned(): void
    {
        $member = $this->createMember();
        $this->freeze('2026-08-14 09:00:00');
        $this->createHistoricalCreditedRow($member);

        // API now reports returned -> historical guard must prevent any reversal.
        $this->fakePage1($this->memberOverrides($member, 'returned'));
        $result = $this->sync();

        $this->assertSame(1, $result->protectedSkipped);
        $this->assertSame(0, $result->cashbackReversed);
        $this->assertSame(0, $this->refunds());
        $this->assertSame(1, $this->walletTx());
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Hoàn thành', $row->affiliate_status, 'historical rows are NEVER flipped');
        $this->assertNull($row->reversed_at);
    }

    // ------------------------------------------------------------------
    //  H: TRUE LOCK — finalized rows are never overwritten by sync
    // ------------------------------------------------------------------

    public function test_h_finalized_row_true_lock_blocks_downgrade_and_drift(): void
    {
        $member = $this->createMember();
        $this->freeze(self::DELIVERED);
        $this->fakePage1($this->memberOverrides($member, 'delivered'));
        $this->sync();

        $this->freeze('2026-08-14 09:00:00');
        $this->finalize();

        // API now claims 'fulfilled' with a HIGHER payout: must be blocked.
        $this->freeze('2026-08-15 00:00:00');
        $this->fakePage1(array_merge(
            $this->memberOverrides($member, 'fulfilled'),
            ['estPayout' => '14000.00'],
        ));
        $result = $this->sync();

        $this->assertSame(1, $result->protectedSkipped);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, $result->cashbackReversed);
        $this->assertSame(1, $this->walletTx());
        $this->assertSame(1, $this->credits());

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Hoàn thành', $row->affiliate_status, 'no downgrade');
        $this->assertSame(6000.0, (float) $row->cashback_amount, 'no recalculation');
        $this->assertSame(6000.0, (float) $row->final_cashback_amount, 'frozen snapshot intact');
        $this->assertNotNull($row->finalized_at);
        $this->assertSame('delivered', $row->lazada_raw_status, 'raw status frozen (drift logged only)');
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  I/J/K: reversal of a finalized order — exactly once, snapshots kept
    // ------------------------------------------------------------------

    public function test_i_finalized_refund_reverses_exactly_once(): void
    {
        $member = $this->createMember();
        $this->syncDelivered($member);
        $this->freeze('2026-08-14 09:00:00');
        $this->finalize();

        $this->fakePage1($this->memberOverrides($member, 'returned'));
        $this->freeze('2026-08-15 00:00:00');
        $result = $this->sync();

        $this->assertSame(1, $result->cashbackReversed);
        $this->assertSame(1, $this->refunds());
        $this->assertSame(6000.0, (float) WalletTransaction::where('type', WalletTransaction::TYPE_REFUND)->first()->amount);
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Đã hủy', $row->affiliate_status);
        $this->assertSame(0.0, (float) $row->cashback_amount);
        $this->assertNotNull($row->reversed_at);
        $this->assertNotNull($row->finalized_at, 'finalized snapshot is preserved');
        $this->assertSame(6000.0, (float) $row->final_cashback_amount, 'frozen final amount preserved');
        $this->assertSame(1, $this->credits(), 'original credit tx preserved, only display cleared');

        // Repeat sync must NOT create a second refund.
        $repeat = $this->sync();
        $this->assertSame(0, $repeat->cashbackReversed);
        $this->assertSame(1, $this->refunds());
        $this->assertSame(2, $this->walletTx(), 'credit + one refund only');
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_j_repeat_returned_after_finalize_never_double_reverses(): void
    {
        $member = $this->createMember();
        $this->syncDelivered($member);
        $this->freeze('2026-08-14 09:00:00');
        $this->finalize();

        $this->fakePage1($this->memberOverrides($member, 'rejected'));
        $this->freeze('2026-08-15 00:00:00');
        $this->sync();
        $this->sync();

        $this->assertSame(1, $this->refunds());
        $this->assertSame(2, $this->walletTx());
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  Q: insufficient balance during reversal aborts atomically
    // ------------------------------------------------------------------

    public function test_q_reversal_with_insufficient_balance_aborts_atomically(): void
    {
        $member = $this->createMember();
        $this->syncDelivered($member);
        $this->freeze('2026-08-14 09:00:00');
        $this->finalize();
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);

        // Simulate the member has already spent the money (raw DB write — the
        // in-memory instance was created with balance 0, so a plain save() no-ops).
        User::where('id', $member->id)->update(['wallet_balance' => 0.0]);

        $this->fakePage1($this->memberOverrides($member, 'returned'));
        $this->freeze('2026-08-15 00:00:00');
        $result = $this->sync();

        $this->assertSame(1, $result->errors, 'InsufficientBalanceException must surface as an error');
        $this->assertSame(0, $result->cashbackReversed);
        $this->assertSame(0, $this->refunds(), 'no refund tx may be created');
        $this->assertSame(1, $this->walletTx(), 'only the original credit remains');
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance, 'balance must never go negative');

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Hoàn thành', $row->affiliate_status, 'no partial state');
        $this->assertSame(6000.0, (float) $row->cashback_amount);
        $this->assertNull($row->reversed_at);
        $this->assertNotNull($row->finalized_at);
    }

    // ------------------------------------------------------------------
    //  M/P: finalizer idempotency
    // ------------------------------------------------------------------

    public function test_m_finalizer_is_idempotent_no_double_credit(): void
    {
        $member = $this->createMember();
        $this->syncDelivered($member);
        $this->freeze('2026-08-14 09:00:00');

        $this->finalize();
        $this->finalize();
        $this->finalize();

        $this->assertSame(1, $this->credits());
        $this->assertSame(1, $this->walletTx());
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
        $this->assertSame(6000.0, (float) AffiliateOrderItem::where('platform', 'Lazada')->first()->final_cashback_amount);

        // Even a fresh sync afterwards must not re-credit.
        $this->fakePage1($this->memberOverrides($member, 'fulfilled'));
        $this->sync();
        $this->assertSame(1, $this->credits());
        $this->assertSame(1, $this->walletTx());
    }

    public function test_p_credit_only_after_mark_finalized_snapshot_consistency(): void
    {
        $member = $this->createMember();
        $this->syncDelivered($member);
        $this->freeze('2026-08-14 09:00:00');
        $this->finalize();

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $credit = WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->first();

        // Money moved and snapshot were written atomically as one unit.
        $this->assertNotNull($credit->completed_at);
        $this->assertSame((float) $credit->amount, (float) $row->final_cashback_amount);
        $this->assertSame((float) $credit->amount, (float) $row->cashback_amount);
        $this->assertSame((float) $credit->amount, (float) $member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  N: the finalizer never touches other platforms
    // ------------------------------------------------------------------

    public function test_n_finalizer_never_touches_other_platforms(): void
    {
        $member = $this->createMember();
        $this->fakePage1($this->memberOverrides($member, 'fulfilled'));
        $this->freeze(self::DELIVERED);
        $this->sync();

        // Plant equivalent "eligible-looking" rows on OTHER platforms.
        AffiliateOrderItem::create(array_merge($this->lazadaBaseRow(), [
            'platform'           => 'TikTok',
            'order_id'           => 'TT-1',
            'lazada_line_key'    => 'tt-key-1',
            'lazada_raw_status'  => 'fulfilled',
            'user_id'            => $member->id,
            'username'           => $member->username,
            'affiliate_status'   => 'Đang xử lý',
            'cashback_amount'    => 6000.00,
            'delivered_at'       => '2026-08-04 09:00:00',
        ]));
        AffiliateOrderItem::create(array_merge($this->lazadaBaseRow(), [
            'platform'            => 'ShopeeFood',
            'order_id'            => 'SF-1',
            'lazada_line_key'     => 'sf-key-1',
            'lazada_raw_status'   => 'fulfilled',
            'user_id'             => $member->id,
            'username'            => $member->username,
            'affiliate_status'    => 'Đang xử lý',
            'cashback_amount'     => 6000.00,
            'delivered_at'        => '2026-08-04 09:00:00',
        ]));

        $this->freeze('2026-08-14 09:00:00');
        $this->finalize();

        $this->assertSame(1, $this->credits(), 'only the Lazada row is paid');
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
        $this->assertNull(AffiliateOrderItem::where('platform', 'TikTok')->first()->finalized_at);
        $this->assertNull(AffiliateOrderItem::where('platform', 'ShopeeFood')->first()->finalized_at);
        $this->assertNotNull(AffiliateOrderItem::where('platform', 'Lazada')->where('order_id', '839912345678901')->first()->finalized_at);
    }

    /**
     * Minimal valid Lazada row used to plant fixtures directly in the DB.
     *
     * @return array<string, mixed>
     */
    private function lazadaBaseRow(): array
    {
        return [
            'order_id'               => '839912345678901',
            'order_status'           => 'Đang xử lý',
            'checkout_id'            => '839912345678901',
            'shop_name'              => 'Seller X',
            'shop_id'                => 679188,
            'item_name'              => 'Son Kem Brand X 01',
            'model_id'               => 0,
            'item_price'             => 200000,
            'quantity'               => 1,
            'order_amount'           => 200000,
            'commission_type'        => 'cps',
            'shopee_commission_rate' => 4,
            'shopee_commission'      => 8000,
            'seller_commission_rate' => 2,
            'total_product_commission' => 12000,
            'order_commission_shopee'  => 12000,
            'order_commission_seller'  => 0,
            'total_order_commission'   => 12000,
            'agreed_commission_rate'   => 6,
            'net_commission'           => 12000,
            'affiliate_status'         => 'Đang xử lý',
            'platform'                 => 'Lazada',
            'lazada_line_key'          => '839912345678901:839912345678902:6021831634002',
            'lazada_raw_status'        => 'fulfilled',
            'import_batch'             => '20260801_000000',
            'source_file'              => 'lazada-api',
            'first_imported_at'        => '2026-08-01 00:00:00',
        ];
    }
}