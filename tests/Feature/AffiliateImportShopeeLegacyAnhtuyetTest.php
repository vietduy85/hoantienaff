<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Services\ShopeeCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateImportShopeeLegacyAnhtuyetTest extends TestCase
{
    use RefreshDatabase;

    private User $legacyUser;
    private User $normalUser;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyUser = User::factory()->create([
            'username' => 'anhtuyet82',
            'wallet_balance' => 0,
        ]);

        $this->normalUser = User::factory()->create([
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

    private function createLegacyExistingRecord(array $overrides = []): AffiliateOrderItem
    {
        return AffiliateOrderItem::create(array_merge([
            'platform' => 'Shopee',
            'order_id' => 'ORDLEGACY',
            'item_id' => 'ITEMLEGACY',
            'order_status' => 'Đã giao',
            'checkout_id' => 'CHK_LEGACY',
            'shop_name' => 'ShopLegacy',
            'shop_id' => 1,
            'item_name' => 'Sản phẩm Legacy',
            'model_id' => 1,
            'item_price' => 100000,
            'quantity' => 1,
            'order_amount' => 100000,
            'commission_type' => 'Shopee Comm',
            'shopee_commission_rate' => 10,
            'shopee_commission' => 10000,
            'seller_commission_rate' => 0,
            'total_product_commission' => 10000,
            'order_commission_shopee' => 10000,
            'order_commission_seller' => 0,
            'total_order_commission' => 10000,
            'agreed_commission_rate' => 100,
            'net_commission' => 10000,
            'affiliate_status' => 'Đang chờ xử lý',
            'sub_id1' => 'anhtuyet',
            'sub_id2' => '82',
            'user_id' => $this->legacyUser->id,
            'username' => 'anhtuyet82',
            'import_batch' => 'seed',
        ], $overrides));
    }

    // ─── 1. Legacy pair preserves existing user_id/username ─────

    public function test_legacy_pair_preserves_user_id_and_username_on_reimport(): void
    {
        $record = $this->createLegacyExistingRecord();

        $csv = $this->buildCsv([
            'order_id' => 'ORDLEGACY',
            'item_id' => 'ITEMLEGACY',
            'sub_id1' => 'anhtuyet',
            'sub_id2' => '82',
            'affiliate_status' => 'Đang chờ xử lý',
        ]);
        $path = $this->createTempCsv($csv);

        $this->artisan('affiliate:import-shopee', ['--file' => $path])
            ->assertSuccessful();

        $record->refresh();

        $this->assertSame($this->legacyUser->id, $record->user_id);
        $this->assertSame('anhtuyet82', $record->username);
        $this->assertSame('anhtuyet', $record->sub_id1);
        $this->assertSame('82', $record->sub_id2);
    }

    // ─── 2. Other order fields still update normally ────────────

    public function test_legacy_pair_still_updates_order_fields(): void
    {
        $record = $this->createLegacyExistingRecord([
            'affiliate_status' => 'Đang chờ xử lý',
            'order_amount' => 100000,
        ]);

        $csv = $this->buildCsv([
            'order_id' => 'ORDLEGACY',
            'item_id' => 'ITEMLEGACY',
            'sub_id1' => 'anhtuyet',
            'sub_id2' => '82',
            'item_price' => '150000',
            'order_amount' => '150000',
            'total_product_commission' => '15000',
            'net_commission' => '15000',
            'affiliate_status' => 'Đã hủy',
        ]);
        $path = $this->createTempCsv($csv);

        $this->artisan('affiliate:import-shopee', ['--file' => $path])
            ->assertSuccessful();

        $record->refresh();

        // Order fields updated from the CSV.
        $this->assertSame('Đã hủy', $record->affiliate_status);
        $this->assertSame('150000.00', (string) $record->order_amount);
        $this->assertSame('150000.00', (string) $record->item_price);

        // user mapping still preserved.
        $this->assertSame($this->legacyUser->id, $record->user_id);
        $this->assertSame('anhtuyet82', $record->username);
    }

    // ─── 3. Normal Shopee user still maps from sub_id1 ──────────

    public function test_normal_user_still_maps_from_sub_id1(): void
    {
        $csv = $this->buildCsv([
            'order_id' => 'ORDNORMAL',
            'item_id' => 'ITEMNORMAL',
            'sub_id1' => 'testuser',
            'affiliate_status' => 'Đang chờ xử lý',
        ]);
        $path = $this->createTempCsv($csv);

        $this->artisan('affiliate:import-shopee', ['--file' => $path])
            ->assertSuccessful();

        $record = AffiliateOrderItem::where('order_id', 'ORDNORMAL')
            ->where('platform', 'Shopee')
            ->first();

        $this->assertNotNull($record);
        $this->assertSame($this->normalUser->id, $record->user_id);
        $this->assertSame('testuser', $record->username);
    }

    // ─── 4. Cashback/wallet logic unchanged ─────────────────────

    public function test_legacy_pair_cashback_credit_still_works_when_completed(): void
    {
        $record = $this->createLegacyExistingRecord([
            'affiliate_status' => 'Đang chờ xử lý',
        ]);

        // Same legacy order now completed → should credit cashback to preserved user.
        $csv = $this->buildCsv([
            'order_id' => 'ORDLEGACY',
            'item_id' => 'ITEMLEGACY',
            'sub_id1' => 'anhtuyet',
            'sub_id2' => '82',
            'item_price' => '100000',
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
            'user_id' => $this->legacyUser->id,
        ]);

        $record->refresh();
        $this->assertSame($this->legacyUser->id, $record->user_id);
        $this->assertSame('anhtuyet82', $record->username);
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
            'sub_id2' => '',
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
        $path = tempnam(sys_get_temp_dir(), 'legacy_test_') . '.csv';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
