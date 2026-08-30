<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
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
        $this->assertSame(4500.0, (float) $this->user->wallet_balance);
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
        $this->assertSame(4500.0, (float) $this->user->fresh()->wallet_balance);
    }

    public function test_full_import_pending_to_cancelled_does_not_create_transaction(): void
    {
        // First import: pending status
        $csv1 = $this->buildCsv([
            'order_id' => 'ORD003',
            'item_id' => 'ITEM003',
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

        // Second import: same order now cancelled
        $csv2 = $this->buildCsv([
            'order_id' => 'ORD003',
            'item_id' => 'ITEM003',
            'item_price' => '100000',
            'quantity' => '1',
            'total_product_commission' => '10000',
            'net_commission' => '10000',
            'affiliate_status' => 'Đã hủy',
        ]);
        $path2 = $this->createTempCsv($csv2);

        $this->artisan('affiliate:import-shopee', ['--file' => $path2])
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->user->refresh();
        $this->assertSame(0.0, (float) $this->user->wallet_balance);
    }

    public function test_import_distinguishes_same_order_and_item_across_platforms(): void
    {
        AffiliateOrderItem::create([
            'platform' => 'TikTok',
            'order_id' => 'ORD001',
            'item_id' => 1,
            'order_status' => 'Đã giao',
            'checkout_id' => 'CHK_TT',
            'shop_name' => 'TikTokShop',
            'shop_id' => 1,
            'item_name' => 'Sản phẩm TikTok',
            'model_id' => 1,
            'item_price' => 0,
            'quantity' => 1,
            'order_amount' => 0,
            'commission_type' => 'Shopee Comm',
            'shopee_commission_rate' => 50,
            'shopee_commission' => 0,
            'seller_commission_rate' => 0,
            'total_product_commission' => 0,
            'order_commission_shopee' => 0,
            'order_commission_seller' => 0,
            'total_order_commission' => 0,
            'agreed_commission_rate' => 0,
            'net_commission' => 0,
            'affiliate_status' => 'Đang chờ xử lý',
            'import_batch' => 'seed',
        ]);

        $csv = $this->buildCsv([
            'item_id' => '1',
            'affiliate_status' => 'Đang chờ xử lý',
        ]);
        $path = $this->createTempCsv($csv);

        $this->artisan('affiliate:import-shopee', ['--file' => $path])
            ->assertSuccessful();

        $shopeeCount = AffiliateOrderItem::where('platform', 'Shopee')
            ->where('order_id', 'ORD001')
            ->where('item_id', 1)
            ->count();
        $tiktokCount = AffiliateOrderItem::where('platform', 'TikTok')
            ->where('order_id', 'ORD001')
            ->where('item_id', 1)
            ->count();

        $this->assertSame(1, $shopeeCount, 'Shopee import nên tạo record Shopee riêng biệt.');
        $this->assertSame(1, $tiktokCount, 'Record TikTok hiện có không được bị xem là record Shopee.');
        $this->assertSame(2, AffiliateOrderItem::count());
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
