<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the Phase 2 migration supports Shopee + TikTok + ShopeeFood
 * simultaneously on the shared affiliate_order_items table.
 */
class ShopeeFoodMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_shopee_and_tiktok_data_is_preserved(): void
    {
        // Two Shopee lines sharing order_id (distinct item_id) - protected by
        // uk_platform_order_item BEFORE the ShopeeFood changes.
        $shopee1 = AffiliateOrderItem::factory()->create([
            'order_id'   => 'SO1',
            'item_id'    => 111,
            'platform'   => 'Shopee',
            'checkout_id' => 'SHC1',
            'promotion_id' => null,
            'shopee_food_line_key' => null,
        ]);
        AffiliateOrderItem::factory()->create([
            'order_id'   => 'SO1',
            'item_id'    => 222,
            'platform'   => 'Shopee',
            'checkout_id' => 'SHC1',
            'promotion_id' => null,
            'shopee_food_line_key' => null,
        ]);

        // A TikTok row uses empty checkout_id and no promotion_id; the new
        // ShopeeFood unique key must not collide with its NULL line key.
        AffiliateOrderItem::factory()->create([
            'order_id'   => 'TO1',
            'item_id'    => 999,
            'platform'   => 'TikTok',
            'checkout_id' => '',
            'promotion_id' => null,
            'shopee_food_line_key' => null,
        ]);

        $this->assertSame('Shopee', $shopee1->fresh()->platform);
        $this->assertSame('TikTok', AffiliateOrderItem::where('platform', 'TikTok')->first()->platform);
        $this->assertSame(3, AffiliateOrderItem::count());
    }

    public function test_shopeefood_can_store_item_id_null_with_valid_business_key(): void
    {
        AffiliateOrderItem::factory()->create([
            'order_id'   => 'SF1',
            'item_id'    => null,
            'platform'   => 'ShopeeFood',
            'checkout_id' => 'SFC1',
            'promotion_id' => 'PROMO-1',
            'shopee_food_line_key' => 'SFC1:PROMO-1',
            'shop_id'   => 1234567890, // 10-digit shop id fits BIGINT
            'item_name' => 'Phở bò',
        ]);

        $row = AffiliateOrderItem::where('platform', 'ShopeeFood')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->item_id);
        $this->assertSame('ShopeeFood', $row->platform);
        $this->assertSame('PROMO-1', $row->promotion_id);
        $this->assertSame('SFC1:PROMO-1', $row->shopee_food_line_key);
    }

    public function test_same_item_id_across_lines_in_one_checkout_is_allowed_for_shopeefood(): void
    {
        // Both lines have the SAME item_id (variant/size) but different promotion_id.
        AffiliateOrderItem::factory()->create([
            'order_id' => 'SF2', 'item_id' => 555, 'platform' => 'ShopeeFood',
            'checkout_id' => 'SFC2', 'promotion_id' => 'P1',
            'shopee_food_line_key' => 'SFC2:P1',
        ]);
        AffiliateOrderItem::factory()->create([
            'order_id' => 'SF3', 'item_id' => 555, 'platform' => 'ShopeeFood',
            'checkout_id' => 'SFC2', 'promotion_id' => 'P2',
            'shopee_food_line_key' => 'SFC2:P2',
        ]);

        $this->assertSame(2, AffiliateOrderItem::where('platform', 'ShopeeFood')->count());
    }

    public function test_shopeefood_duplicate_line_key_is_rejected(): void
    {
        $row = [
            'order_id' => 'SF4', 'item_id' => null, 'platform' => 'ShopeeFood',
            'checkout_id' => 'SFC3', 'promotion_id' => 'P1',
            'shopee_food_line_key' => 'SFC3:P1',
        ];

        AffiliateOrderItem::factory()->create($row);

        $this->expectException(QueryException::class);
        AffiliateOrderItem::factory()->create($row);
    }

    public function test_shopeefood_record_has_own_sync_timestamp_column(): void
    {
        $this->assertTrue(Schema::hasColumn('affiliate_order_items', 'last_shopeefood_sync_at'));

        $row = AffiliateOrderItem::factory()->create([
            'platform' => 'ShopeeFood',
            'checkout_id' => 'SFC5',
            'promotion_id' => 'P1',
            'shopee_food_line_key' => 'SFC5:P1',
            'last_shopeefood_sync_at' => now(),
        ]);

        $this->assertNotNull($row->fresh()->last_shopeefood_sync_at);
    }
}
