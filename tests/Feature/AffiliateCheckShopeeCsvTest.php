<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ShopeeCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * affiliate:check-shopee-csv — Phase 3 §11/§12:
 * read-only CSV ↔ DB audit with banner/COMPARISON/VERDICT output and
 * exit codes (0 = PASS/WARNING, 1 = FAIL). Never calls the API, never
 * writes DB/wallet, never runs inside affiliate:sync-all.
 */
class AffiliateCheckShopeeCsvTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create([
            'username' => 'alice123',
            'wallet_balance' => 0,
            'total_earned' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Writes a minimal-but-valid Shopee Affiliate Commission Report CSV.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $header = [
            'ID đơn hàng',
            'Trạng thái đặt hàng',
            'Item id',
            'Tên Item',
            'Giá(₫)',
            'Số lượng',
            'Giá trị đơn hàng (₫)',
            'Tổng hoa hồng sản phẩm(₫)',
            'Phí quản lý MCN(₫)',
            'Hoa hồng ròng tiếp thị liên kết(₫)',
            'Trạng thái sản phẩm liên kết',
            'Sub_id1',
            'Kênh',
        ];

        $lines = [implode(',', $header)];

        foreach ($rows as $row) {
            $lines[] = implode(',', [
                $row['order_id'],
                $row['order_status'] ?? 'Hoàn thành',
                $row['item_id'],
                'Test product',
                $row['item_price'] ?? 100000,
                $row['quantity'] ?? 1,
                $row['order_amount'] ?? 100000,
                $row['commission'] ?? 10000,
                0,
                $row['commission'] ?? 10000,
                $row['affiliate_status'] ?? 'Hoàn thành',
                $row['sub_id1'] ?? 'alice123',
                'Shopee',
            ]);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'shopee_csv_');
        $path .= '.csv';
        file_put_contents($path, implode("\n", $lines)."\n");
        $this->tempFiles[] = $path;

        return $path;
    }

    /** CSV row ORD-1/111222333 whose values match the DB row below. */
    private function csvRow(array $overrides = []): array
    {
        return array_merge([
            'order_id' => 'ORD-1',
            'item_id' => '111222333',
            'quantity' => 1,
            'commission' => 10000,
        ], $overrides);
    }

    private function makeDbRow(array $overrides = []): AffiliateOrderItem
    {
        return AffiliateOrderItem::create(array_merge([
            'order_id' => 'ORD-1',
            'order_status' => 'Hoàn thành',
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
            'affiliate_status' => 'Hoàn thành',
            'sub_id1' => 'alice123',
            'platform' => 'Shopee',
            'user_id' => $this->alice->id,
            'username' => 'alice123',
            'cashback_rate' => 50,
            'cashback_amount' => 4500,
            'import_batch' => 'csv_batch',
            'source_file' => 'AffiliateCommissionReport.csv',
        ], $overrides));
    }

    // ------------------------------------------------------------------
    //  1. PASS: CSV and DB agree exactly
    // ------------------------------------------------------------------

    public function test_pass_when_csv_matches_db_exactly(): void
    {
        $file = $this->writeCsv([$this->csvRow()]);
        $this->makeDbRow();

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $file])
            ->expectsOutputToContain('SHOPEE CSV RECONCILIATION')
            ->expectsOutputToContain('COMPARISON')
            ->expectsOutputToContain('Match:')
            ->expectsOutputToContain('VERDICT')
            ->expectsOutputToContain('PASS')
            ->expectsOutputToContain('READ-ONLY: no DB writes, no wallet writes, no API calls.')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------
    //  2. Rounding delta <= 1 → WARNING, still exit 0
    // ------------------------------------------------------------------

    public function test_rounding_difference_is_warning_with_exit_zero(): void
    {
        $file = $this->writeCsv([$this->csvRow()]);
        $this->makeDbRow(['total_product_commission' => 10000.5]);

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $file])
            ->expectsOutputToContain('Commission mismatch:')
            ->expectsOutputToContain('ROUNDING (<=1): 1')
            ->expectsOutputToContain('WARNING')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------
    //  3. CSV_ONLY (DB behind snapshot) → WARNING, not a failure
    // ------------------------------------------------------------------

    public function test_csv_only_row_is_warning_with_exit_zero(): void
    {
        $file = $this->writeCsv([
            $this->csvRow(),
            $this->csvRow(['order_id' => 'ORD-2', 'item_id' => '222333444']),
        ]);
        $this->makeDbRow();

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $file])
            ->expectsOutputToContain('CSV_ONLY: 1 (')
            ->expectsOutputToContain('WARNING')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------
    //  4. Quantity difference is CRITICAL → FAIL, exit 1
    // ------------------------------------------------------------------

    public function test_quantity_mismatch_is_critical_failure(): void
    {
        $file = $this->writeCsv([$this->csvRow()]);
        $this->makeDbRow(['quantity' => 2]);

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $file])
            ->expectsOutputToContain('Quantity mismatch:')
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------------
    //  5. Two different existing users on the same row → FAIL, exit 1
    // ------------------------------------------------------------------

    public function test_user_mapping_conflict_is_critical_failure(): void
    {
        User::factory()->create(['username' => 'bob123', 'wallet_balance' => 0]);

        $file = $this->writeCsv([$this->csvRow(['sub_id1' => 'bob123'])]);
        $this->makeDbRow();

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $file])
            ->expectsOutputToContain('User mapping mismatch:')
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------------
    //  6. Money delta > 1 → CRITICAL → FAIL, exit 1
    // ------------------------------------------------------------------

    public function test_commission_mismatch_beyond_rounding_is_critical_failure(): void
    {
        $file = $this->writeCsv([$this->csvRow()]);
        $this->makeDbRow(['total_product_commission' => 20000]);

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $file])
            ->expectsOutputToContain('Commission mismatch:')
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------------
    //  7. Unknown sub_id1 (no user, differs from DB) → WARNING, exit 0
    // ------------------------------------------------------------------

    public function test_sub_id_mismatch_is_warning_with_exit_zero(): void
    {
        $file = $this->writeCsv([$this->csvRow(['sub_id1' => 'ghost-user'])]);
        $this->makeDbRow();

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $file])
            ->expectsOutputToContain('Sub ID mismatch:')
            ->expectsOutputToContain('WARNING')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------
    //  8. Unreadable / invalid CSV → exit 1
    // ------------------------------------------------------------------

    public function test_missing_file_exits_with_failure(): void
    {
        $this->artisan('affiliate:check-shopee-csv', [
            '--file' => 'C:\does\not\exist\report.csv',
        ])->assertExitCode(1);
    }

    public function test_invalid_csv_header_exits_with_failure(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bad_csv_');
        $path .= '.csv';
        file_put_contents($path, "a,b,c\n1,2,3\n");
        $this->tempFiles[] = $path;

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $path])
            ->expectsOutputToContain('Invalid CSV header')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------------
    //  9. STRICTLY READ-ONLY: no HTTP, no DB write, no wallet write
    // ------------------------------------------------------------------

    public function test_command_makes_no_api_calls_and_writes_nothing(): void
    {
        Http::fake();

        $file = $this->writeCsv([$this->csvRow()]);
        $row = $this->makeDbRow();

        $this->artisan('affiliate:check-shopee-csv', ['--file' => $file])
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, AffiliateOrderItem::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame($row->fresh()->cashback_amount, $row->cashback_amount);
        $this->assertSame(0.0, (float) $this->alice->fresh()->wallet_balance);
    }

    // ------------------------------------------------------------------
    //  10. No --file → auto-picks the newest *.csv in Downloads
    // ------------------------------------------------------------------

    public function test_without_file_option_uses_newest_csv_in_downloads(): void
    {
        $newest = $this->newestDownloadsCsv();
        if ($newest === null
            || ! app(ShopeeCsvParser::class)->validateHeader($newest)['is_valid']) {
            $this->markTestSkipped('No valid Shopee CSV found in Downloads for the auto-discovery test.');
        }

        $this->artisan('affiliate:check-shopee-csv')
            ->expectsOutputToContain(basename($newest));
    }

    private function newestDownloadsCsv(): ?string
    {
        $files = glob('C:\Users\Administrator\Downloads\*.csv');
        if (! $files) {
            return null;
        }

        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }
}
