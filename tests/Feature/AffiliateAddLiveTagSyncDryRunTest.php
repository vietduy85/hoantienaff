<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ShopeeCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AffiliateAddLiveTagSyncDryRunTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.addlivetag.page_delay_ms' => 0,
            'services.addlivetag.max_sync_pages' => 5,
        ]);

        $this->user = User::factory()->create([
            'username' => 'testuser',
            'wallet_balance' => 0,
        ]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function apiRow(array $overrides = []): array
    {
        return array_merge([
            'order_sn' => '261006AAA111',
            'checkout_id' => 'CO-1',
            'purchase_time' => '2026-10-06 10:00:00',
            'complete_time' => 0,
            'click_time' => '2026-10-05 09:00:00',
            'item_url' => 'https://addlivetag.com/product/?item_id=111222333',
            'item_name' => 'Test product',
            'price' => 100000,
            'qty' => 1,
            'order_value' => 100000,
            'commission' => 10000,
            'mcn_fee' => 0,
            'sub_id1' => 'testuser',
            'status_code' => 'completed',
            'commission_status' => 'Đã chốt',
        ], $overrides);
    }

    private function fakeApi(array $rows): void
    {
        Http::fakeSequence()
            ->push(['data' => $rows], 200)
            ->push(['data' => []], 200);
    }

    private function counts(): array
    {
        return [
            'items' => AffiliateOrderItem::count(),
            'wallet' => WalletTransaction::count(),
            'balance' => (float) User::sum('wallet_balance'),
            'finalized' => AffiliateOrderItem::whereNotNull('finalized_at')->count(),
            'locked' => AffiliateOrderItem::whereNotNull('locked_at')->count(),
        ];
    }

    // ------------------------------------------------------------------
    // 1-2. dry-run: ZERO DB writes / ZERO wallet transactions
    // ------------------------------------------------------------------

    public function test_dry_run_produces_zero_db_and_wallet_writes(): void
    {
        $this->fakeApi([$this->apiRow()]);

        $before = $this->counts();

        $this->artisan('affiliate:addlivetag-sync', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
        $this->assertDatabaseCount('affiliate_order_items', $before['items']);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    // ------------------------------------------------------------------
    // 3. completed API row does not credit wallet
    // ------------------------------------------------------------------

    public function test_completed_api_row_never_credits_wallet(): void
    {
        $this->fakeApi([$this->apiRow(['status_code' => 'completed'])]);

        $this->artisan('affiliate:addlivetag-sync', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->user->refresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    // 4. unresolved sub_id1 does not credit wallet
    // ------------------------------------------------------------------

    public function test_unresolved_sub_id1_does_not_credit_wallet(): void
    {
        $this->fakeApi([$this->apiRow(['sub_id1' => 'khong-ton-tai-user'])]);

        $this->artisan('affiliate:addlivetag-sync', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_apply_mode_unresolved_sub_id1_stores_null_user_and_no_wallet(): void
    {
        $this->fakeApi([$this->apiRow(['sub_id1' => 'khong-ton-tai-user'])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertSame('khong-ton-tai-user', $row->username);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->user->refresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    // 5-6. locked / finalized rows protected
    // ------------------------------------------------------------------

    public function test_locked_row_is_protected(): void
    {
        $existing = $this->makeExistingRow([
            'affiliate_status' => 'Đang chờ xử lý',
            'total_product_commission' => 5000,
        ]);
        $existing->locked_at = now();
        $existing->save();

        $this->fakeApi([$this->apiRow([
            'order_sn' => $existing->order_id,
            'item_url' => 'https://addlivetag.com/product/?item_id='.$existing->item_id,
            'status_code' => 'completed',
            'commission' => 99999,
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--dry-run' => true])
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame('Đang chờ xử lý', $existing->affiliate_status);
        $this->assertEquals(5000.0, (float) $existing->total_product_commission);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_finalized_row_is_protected(): void
    {
        $existing = $this->makeExistingRow([
            'affiliate_status' => 'Hoàn thành',
            'total_product_commission' => 5000,
        ]);
        $existing->markFinalized('shopee_csv_import');

        $this->fakeApi([$this->apiRow([
            'order_sn' => $existing->order_id,
            'item_url' => 'https://addlivetag.com/product/?item_id='.$existing->item_id,
            'status_code' => 'cancelled',
            'commission' => 1,
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame('Hoàn thành', $existing->affiliate_status);
        $this->assertEquals(5000.0, (float) $existing->total_product_commission);
        $this->assertNotNull($existing->finalized_at);
    }

    // ------------------------------------------------------------------
    // 7. duplicate (order_sn,item_id) deterministic LAST-WINS
    // ------------------------------------------------------------------

    public function test_duplicate_overlap_uses_deterministic_last_wins(): void
    {
        $rows = [
            $this->apiRow(['qty' => 1, 'price' => 329000, 'order_value' => 246750, 'commission' => 38009]),
            $this->apiRow(['qty' => 2, 'price' => 329000, 'order_value' => 493500, 'commission' => 76016]),
        ];
        $this->fakeApi($rows);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $dbRows = AffiliateOrderItem::where('order_id', '261006AAA111')->get();
        $this->assertCount(1, $dbRows, 'overlap must collapse to one row (uk order+item)');
        $row = $dbRows->first();
        $this->assertSame(2, (int) $row->quantity, 'LAST line wins — no quantity summation');
        $this->assertEquals(493500.0, (float) $row->order_amount);
        $this->assertEquals(76016.0, (float) $row->total_product_commission);
    }

    // ------------------------------------------------------------------
    // 8. cashback formula equals ShopeeCsvParser
    // ------------------------------------------------------------------

    public function test_cashback_matches_shopee_csv_parser(): void
    {
        $this->fakeApi([$this->apiRow([
            'price' => 250000,
            'qty' => 1,
            'commission' => 15000,
            'status_code' => 'completed',
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();

        $expected = (new ShopeeCsvParser)->calculateCashback(15000.0, 250000.0);

        $this->assertSame($expected['rate'], (int) $row->cashback_rate);
        $this->assertEqualsWithDelta($expected['amount'], (float) $row->cashback_amount, 0.01);
    }

    // ------------------------------------------------------------------
    // 9. status mapping
    // ------------------------------------------------------------------

    public function test_status_mapping(): void
    {
        $map = [
            'completed' => 'Hoàn thành',
            'paid' => 'Đang chờ xử lý',
            'unpaid' => 'Đang chờ xử lý',
            'cancelled' => 'Đã hủy',
        ];

        $rows = [];
        foreach ($map as $code => $expected) {
            $rows[] = $this->apiRow([
                'order_sn' => 'ORDER_'.$code,
                'status_code' => $code,
            ]);
        }
        $this->fakeApi($rows);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        foreach ($map as $code => $expected) {
            $row = AffiliateOrderItem::where('order_id', 'ORDER_'.$code)->firstOrFail();
            $this->assertSame($expected, $row->affiliate_status, 'status_code '.$code);
            $this->assertSame($expected, $row->order_status, 'order_status for '.$code);
        }
    }

    public function test_commission_status_is_not_used_as_completion_gate(): void
    {
        $this->fakeApi([$this->apiRow([
            'status_code' => 'completed',
            'commission_status' => 'Chưa chốt',
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertSame('Hoàn thành', $row->affiliate_status);
    }

    // ------------------------------------------------------------------
    // 10. API newer pending -> completed detected
    // ------------------------------------------------------------------

    public function test_api_newer_pending_to_completed_is_detected(): void
    {
        $existing = $this->makeExistingRow([
            'affiliate_status' => 'Đang chờ xử lý',
            'order_status' => 'Đang chờ xử lý',
            'total_product_commission' => 10000,
            'cashback_amount' => 0,
        ]);

        $this->fakeApi([$this->apiRow([
            'order_sn' => $existing->order_id,
            'item_url' => 'https://addlivetag.com/product/?item_id='.$existing->item_id,
            'status_code' => 'completed',
            'complete_time' => '2026-10-07 08:00:00',
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame('Hoàn thành', $existing->affiliate_status, 'API pending->completed must be applied to ordinary row');
        $this->assertNotNull($existing->completed_at);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->user->refresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    // 11. CSV completed + API paid => no downgrade of protected completed state
    // ------------------------------------------------------------------

    public function test_csv_completed_with_api_paid_is_not_downgraded(): void
    {
        $existing = $this->makeExistingRow([
            'affiliate_status' => 'Hoàn thành',
            'order_status' => 'Hoàn thành',
            'total_product_commission' => 10000,
            'cashback_amount' => 4500,
        ]);

        $this->fakeApi([$this->apiRow([
            'order_sn' => $existing->order_id,
            'item_url' => 'https://addlivetag.com/product/?item_id='.$existing->item_id,
            'status_code' => 'paid',
            'complete_time' => 0,
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame('Hoàn thành', $existing->affiliate_status, 'completed must NOT be downgraded by API lag');
    }

    // ------------------------------------------------------------------
    // 12-13. source_file / import_batch
    // ------------------------------------------------------------------

    public function test_source_file_and_import_batch_are_set(): void
    {
        $this->fakeApi([$this->apiRow()]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertSame('addlivetag-api', $row->source_file);
        $this->assertMatchesRegularExpression('/^\d{8}_\d{6}$/', (string) $row->import_batch);
        $this->assertNotNull($row->last_shopee_sync_at);
    }

    // ------------------------------------------------------------------
    // 14. item_id extracted from item_url
    // ------------------------------------------------------------------

    public function test_item_id_extracted_from_addlivetag_item_url(): void
    {
        $this->fakeApi([$this->apiRow([
            'item_url' => 'https://addlivetag.com/product/?item_id=53509055844',
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertSame('53509055844', (string) $row->item_id);
    }

    // ------------------------------------------------------------------
    // 15. USER_MAPPING_CONFLICT reported, existing mapping untouched
    // ------------------------------------------------------------------

    public function test_user_mapping_conflict_is_reported_and_not_overwritten(): void
    {
        $other = User::factory()->create(['username' => 'linhvtn130794', 'wallet_balance' => 0]);

        $existing = $this->makeExistingRow([
            'affiliate_status' => 'Hoàn thành',
            'total_product_commission' => 10000,
        ]);
        $existing->user_id = $this->user->id;
        $existing->username = 'duongnguyenkimphuong';
        $existing->sub_id1 = 'duongnguyenkimphuong';
        $existing->save();

        $this->fakeApi([$this->apiRow([
            'order_sn' => $existing->order_id,
            'item_url' => 'https://addlivetag.com/product/?item_id='.$existing->item_id,
            'sub_id1' => 'linhvtn130794',
            'status_code' => 'completed',
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--dry-run' => true])
            ->expectsOutputToContain('USER_MAPPING_CONFLICT')
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame($this->user->id, $existing->user_id, 'existing user mapping must NOT be overwritten');
        $this->assertSame('duongnguyenkimphuong', $existing->username);
        $this->assertDatabaseCount('wallet_transactions', 0);
        unset($other);
    }

    // ------------------------------------------------------------------
    // 15b. SUB_ID_MISMATCH info-only (API sub_id1 resolves to nobody)
    // ------------------------------------------------------------------

    public function test_sub_id_mismatch_is_info_only_and_keeps_mapping(): void
    {
        $existing = $this->makeExistingRow([
            'affiliate_status' => 'Đang chờ xử lý',
            'total_product_commission' => 5000,
        ]);
        $existing->user_id = $this->user->id;
        $existing->username = 'anhtuyet82';
        $existing->sub_id1 = 'anhtuyet82';
        $existing->save();

        // API sends a sub_id1 that matches NO users.username → not a
        // USER_MAPPING_CONFLICT, just an informational mismatch.
        $this->fakeApi([$this->apiRow([
            'order_sn' => $existing->order_id,
            'item_url' => 'https://addlivetag.com/product/?item_id='.$existing->item_id,
            'sub_id1' => 'anhtuyet',
            'status_code' => 'paid',
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--dry-run' => true])
            ->expectsOutputToContain('[SUB_ID_MISMATCH]')
            ->doesntExpectOutputToContain('[USER_MAPPING_CONFLICT]')
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame($this->user->id, $existing->user_id);
        $this->assertSame('anhtuyet82', $existing->username);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    // ------------------------------------------------------------------
    // 16. truncated pagination is failure
    // ------------------------------------------------------------------

    public function test_truncated_pagination_is_failure_not_success(): void
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = $this->apiRow(['order_sn' => 'TRUNC-'.$i, 'item_url' => 'https://addlivetag.com/product/?item_id='.$i]);
        }
        Http::fake([
            'https://addlivetag.com/api/v1/conversions.php*' => Http::response(['data' => $rows], 200),
        ]);

        $before = $this->counts();

        $this->artisan('affiliate:addlivetag-sync', ['--dry-run' => true])
            ->assertFailed();

        $this->assertSame($before, $this->counts());
    }

    // ------------------------------------------------------------------
    // reconcile: zero DB writes too
    // ------------------------------------------------------------------

    public function test_reconcile_mode_produces_zero_writes(): void
    {
        $this->fakeApi([$this->apiRow()]);
        $before = $this->counts();

        $this->artisan('affiliate:addlivetag-sync', ['--reconcile' => true])
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
    }

    // ------------------------------------------------------------------
    // default (no flag): safe read-only unless --apply
    // ------------------------------------------------------------------

    public function test_default_mode_without_apply_is_read_only(): void
    {
        $this->fakeApi([$this->apiRow()]);
        $before = $this->counts();

        $this->artisan('affiliate:addlivetag-sync')
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
    }

    // ------------------------------------------------------------------
    // no_wallet_service_guarantee: even --apply never writes wallet
    // ------------------------------------------------------------------

    public function test_apply_mode_never_creates_wallet_transactions(): void
    {
        $this->fakeApi([$this->apiRow(['status_code' => 'completed'])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertSame('Hoàn thành', $row->affiliate_status);
        $this->assertGreaterThan(0, (float) $row->cashback_amount, 'cashback amount prepared');
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->user->refresh()->wallet_balance);
        $this->assertSame(0.0, (float) $this->user->refresh()->total_earned);
    }

    // ------------------------------------------------------------------
    // Real-API behavior (verified 2026-10-08 against live endpoint):
    // purchase_time / complete_time / click_time are UNIX timestamps.
    // ------------------------------------------------------------------

    public function test_unix_timestamps_are_converted_to_datetime(): void
    {
        $this->fakeApi([$this->apiRow([
            'purchase_time' => 1791243556,   // 2026-10-06 13:19:16 +07
            'click_time' => 1791274873,
            'complete_time' => 1791293997,
            'status_code' => 'completed',
        ])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertSame(date('Y-m-d H:i:s', 1791243556), $row->ordered_at->format('Y-m-d H:i:s'));
        $this->assertSame(date('Y-m-d H:i:s', 1791274873), $row->clicked_at->format('Y-m-d H:i:s'));
        $this->assertSame(date('Y-m-d H:i:s', 1791293997), $row->completed_at->format('Y-m-d H:i:s'));
    }

    public function test_complete_time_zero_becomes_null(): void
    {
        $this->fakeApi([$this->apiRow(['complete_time' => 0, 'status_code' => 'paid'])]);

        $this->artisan('affiliate:addlivetag-sync', ['--apply' => true])
            ->assertSuccessful();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertNull($row->completed_at);
    }

    // ------------------------------------------------------------------

    private function makeExistingRow(array $overrides = []): AffiliateOrderItem
    {
        $data = array_merge([
            'order_id' => '261006AAA111',
            'order_status' => 'Đang chờ xử lý',
            'checkout_id' => 'CO-1',
            'ordered_at' => now(),
            'item_id' => '111222333',
            'item_name' => 'Test product',
            'shop_name' => 'Test shop',
            'shop_id' => 1,
            'model_id' => 0,
            'commission_type' => 'Shopee Comm',
            'shopee_commission_rate' => 0,
            'shopee_commission' => 0,
            'seller_commission_rate' => 0,
            'xtra_commission' => 0,
            'order_commission_shopee' => 0,
            'order_commission_seller' => 0,
            'total_order_commission' => 10000,
            'agreed_commission_rate' => 0,
            'net_commission' => 10000,
            'item_price' => 100000,
            'quantity' => 1,
            'order_amount' => 100000,
            'total_product_commission' => 10000,
            'mcn_management_fee' => 0,
            'affiliate_status' => 'Đang chờ xử lý',
            'sub_id1' => 'testuser',
            'platform' => 'Shopee',
            'user_id' => $this->user->id,
            'username' => 'testuser',
            'cashback_rate' => 0,
            'cashback_amount' => 0,
            'import_batch' => 'csv_batch',
            'source_file' => 'AffiliateCommissionReport.csv',
        ], $overrides);

        return AffiliateOrderItem::create($data);
    }
}
