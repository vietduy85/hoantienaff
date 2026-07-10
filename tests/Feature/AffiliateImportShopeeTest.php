<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ShopeeCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateImportShopeeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'wallet_balance' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_full_import_completed_order_creates_cashback_and_updates_balance(): void
    {
        $csv = $this->buildCsv([
            'item_price' => '100000',
            'quantity' => '1',
            'total_product_commission' => '10000',
            'net_commission' => '10000',
            'affiliate_status' => 'Hoàn thành',
        ]);
        $path = $this->createTempCsv($csv);

        $this->artisan('affiliate:import-shopee', ['--file' => $path])
            ->assertSuccessful();

        $this->assertDatabaseHas('wallet_transactions', [
            'reference_type' => 'affiliate_order_item',
            'type' => 'cashback',
            'direction' => 'credit',
            'status' => 'completed',
        ]);

        $this->user->refresh();
        $this->assertSame(5000.0, (float) $this->user->wallet_balance);
    }

    public function test_full_import_status_transition_credits_cashback(): void
    {
        // First import: non-completed status
        $csv1 = $this->buildCsv([
            'item_price' => '100000',
            'quantity' => '1',
            'total_product_commission' => '10000',
            'net_commission' => '10000',
            'affiliate_status' => 'Đang chờ xử lý',
        ]);
        $path1 = $this->createTempCsv($csv1);

        $this->artisan('affiliate:import-shopee', ['--file' => $path1])
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->user->fresh()->wallet_balance);

        // Second import: status changes to completed
        $csv2 = $this->buildCsv([
            'item_price' => '100000',
            'quantity' => '1',
            'total_product_commission' => '10000',
            'net_commission' => '10000',
            'affiliate_status' => 'Hoàn thành',
        ]);
        $path2 = $this->createTempCsv($csv2);

        $this->artisan('affiliate:import-shopee', ['--file' => $path2])
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(5000.0, (float) $this->user->fresh()->wallet_balance);
    }

    private function buildCsv(array $overrides): string
    {
        $defaults = [
            'order_id' => 'ORD001',
            'order_status' => 'Đã giao',
            'checkout_id' => 'CHK001',
            'shop_name' => 'ShopTest',
            'shop_id' => '1',
            'item_id' => 'ITEM001',
            'item_name' => 'Sản phẩm A',
            'model_id' => '100',
            'commission_type' => 'Shopee Comm',
            'sub_id1' => 'testuser',
            'item_price' => '100000',
            'quantity' => '1',
            'total_product_commission' => '10000',
            'net_commission' => '10000',
            'affiliate_status' => 'Hoàn thành',
        ];

        $data = array_merge($defaults, $overrides);

        $parser = new ShopeeCsvParser;
        $headerMap = $parser->getHeaderMap();
        $headers = array_keys($headerMap);
        $values = array_map(fn (string $header) => $data[$headerMap[$header]] ?? '', $headers);

        return implode(',', $headers) . "\n" . implode(',', $values) . "\n";
    }

    private function createTempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import_test_') . '.csv';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
