<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AddLiveTag\ConversionsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * affiliate:sync-all step 5 (Shopee/AddLiveTag) — Phase 2 enablement:
 * APPLY + wallet credit by default, --shopee-dry-run stays read-only.
 *
 * Credit goes ONLY through ConversionsImporter::credit() →
 * WalletService::creditCashback() with the §11 guards.
 */
class AffiliateSyncAllShopeeStep5Test extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->member = User::factory()->create([
            'username' => 'alice123',
            'wallet_balance' => 0,
            'total_earned' => 0,
        ]);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function shopeeApiRow(array $overrides = []): array
    {
        return array_merge([
            'order_sn' => '261006AAA111',
            'checkout_id' => 'CO-1',
            'purchase_time' => '2026-10-06 10:00:00',
            'complete_time' => '2026-10-07 08:00:00',
            'click_time' => '2026-10-05 09:00:00',
            'item_url' => 'https://addlivetag.com/product/?item_id=111222333',
            'item_name' => 'Test product',
            'price' => 100000,
            'qty' => 1,
            'order_value' => 100000,
            'commission' => 10000,
            'mcn_fee' => 0,
            'sub_id1' => 'alice123',
            'status_code' => 'completed',
            'commission_status' => 'Đã chốt',
        ], $overrides);
    }

    private function stubShopeeApi(array $rows): void
    {
        $this->app->instance(ConversionsClient::class, new class($rows) extends ConversionsClient
        {
            public function __construct(private readonly array $rows) {}

            public function fetch(
                ?string $from = null,
                ?string $to = null,
                ?string $orderId = null,
                int $page = 1,
                ?int $pageSizeOverride = null,
            ): array {
                return ['items' => $this->rows, 'truncated' => false, 'pages_fetched' => 1];
            }
        });
    }

    private function runShopeeStep(array $options = []): void
    {
        $this->artisan('affiliate:sync-all', array_merge(
            ['--shopee-only' => true],
            $options,
        ))->assertSuccessful();
    }

    // ------------------------------------------------------------------
    //  1. --shopee-dry-run: ZERO row writes, ZERO wallet writes
    // ------------------------------------------------------------------

    public function test_dry_run_creates_no_rows_and_no_wallet_transactions(): void
    {
        $this->stubShopeeApi([$this->shopeeApiRow()]);

        $this->runShopeeStep(['--shopee-dry-run' => true]);

        $this->assertDatabaseCount('affiliate_order_items', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  2. Default = APPLY + CREDIT, idempotent across runs
    // ------------------------------------------------------------------

    public function test_default_run_creates_row_and_credits_wallet_exactly_once(): void
    {
        $this->stubShopeeApi([$this->shopeeApiRow()]);

        $this->runShopeeStep();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertSame('Hoàn thành', $row->affiliate_status);
        $this->assertSame($this->member->id, $row->user_id);
        $this->assertGreaterThan(0, (float) $row->cashback_amount);

        $expectedCashback = (float) $row->cashback_amount;
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame($expectedCashback, (float) $this->member->fresh()->wallet_balance);

        // Second run: no duplicate row, no duplicate credit.
        $this->runShopeeStep();

        $this->assertSame(1, AffiliateOrderItem::where('order_id', '261006AAA111')->count());
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame($expectedCashback, (float) $this->member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  3. Unresolved sub_id1 → row stored with NULL user, no credit
    // ------------------------------------------------------------------

    public function test_unresolved_sub_id1_stores_null_user_and_never_credits(): void
    {
        $this->stubShopeeApi([$this->shopeeApiRow(['sub_id1' => 'khong-ton-tai-user'])]);

        $this->runShopeeStep();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  4. Cancelled row: stored, never credited (no new credit, no reversal)
    // ------------------------------------------------------------------

    public function test_cancelled_row_is_never_credited(): void
    {
        $this->stubShopeeApi([$this->shopeeApiRow(['status_code' => 'cancelled'])]);

        $this->runShopeeStep();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertSame('Đã hủy', $row->affiliate_status);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    // ------------------------------------------------------------------
    //  5. Already-credited row stays protected (no double credit, no rewrite)
    // ------------------------------------------------------------------

    public function test_already_credited_row_is_not_double_credited(): void
    {
        $existing = $this->makeExistingRow([
            'affiliate_status' => 'Hoàn thành',
            'order_status' => 'Hoàn thành',
            'total_product_commission' => 10000,
            'cashback_amount' => 4500,
        ]);

        WalletTransaction::factory()->create([
            'user_id' => $this->member->id,
            'username' => 'alice123',
            'platform' => 'Shopee',
            'type' => WalletTransaction::TYPE_CASHBACK,
            'direction' => 'credit',
            'amount' => 4500,
            'reference_type' => 'affiliate_order_item',
            'reference_id' => $existing->id,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $this->member->increment('wallet_balance', 4500);

        // API sends a DIFFERENT commission — protected row must not change
        // and must not receive a second credit.
        $this->stubShopeeApi([$this->shopeeApiRow(['commission' => 99999])]);

        $this->runShopeeStep();

        $existing->refresh();
        $this->assertSame('Hoàn thành', $existing->affiliate_status);
        $this->assertSame(10000.0, (float) $existing->total_product_commission);
        $this->assertSame(4500.0, (float) $existing->cashback_amount);
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(4500.0, (float) $this->member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  6. USER_MAPPING_CONFLICT → reported, NOT credited, NOT remapped
    // ------------------------------------------------------------------

    public function test_user_mapping_conflict_is_skipped_for_credit(): void
    {
        User::factory()->create(['username' => 'linhvtn130794', 'wallet_balance' => 0]);

        // Existing completed row mapped to alice123, NOT yet credited.
        $existing = $this->makeExistingRow([
            'affiliate_status' => 'Hoàn thành',
            'order_status' => 'Hoàn thành',
            'total_product_commission' => 10000,
            'cashback_amount' => 4500,
        ]);

        // API reports sub_id1 of a DIFFERENT existing user → conflict.
        $this->stubShopeeApi([$this->shopeeApiRow(['sub_id1' => 'linhvtn130794'])]);

        $this->artisan('affiliate:sync-all', ['--shopee-only' => true])
            ->expectsOutputToContain('user_conflict: 1')
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame($this->member->id, $existing->user_id, 'existing mapping must NOT be remapped');
        $this->assertSame('alice123', $existing->username);
        $this->assertSame(0, WalletTransaction::count(), 'conflicted row must not be credited');
        $this->assertSame(0.0, (float) $this->member->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  7. Pending API row → stored as pending, no credit
    // ------------------------------------------------------------------

    public function test_pending_row_is_stored_without_credit(): void
    {
        $this->stubShopeeApi([$this->shopeeApiRow([
            'status_code' => 'paid',
            'complete_time' => 0,
        ])]);

        $this->runShopeeStep();

        $row = AffiliateOrderItem::where('order_id', '261006AAA111')->firstOrFail();
        $this->assertSame('Đang chờ xử lý', $row->affiliate_status);
        $this->assertDatabaseCount('wallet_transactions', 0);
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
            'affiliate_status' => 'Đang chờ xử lý',
            'sub_id1' => 'alice123',
            'platform' => 'Shopee',
            'user_id' => $this->member->id,
            'username' => 'alice123',
            'cashback_rate' => 0,
            'cashback_amount' => 0,
            'import_batch' => 'csv_batch',
            'source_file' => 'AffiliateCommissionReport.csv',
        ], $overrides);

        return AffiliateOrderItem::create($data);
    }
}
