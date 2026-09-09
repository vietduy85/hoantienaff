<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the Lazada migration supports Shopee + TikTok + ShopeeFood + Lazada
 * simultaneously on the shared affiliate_order_items table.
 */
class LazadaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_platforms_are_preserved(): void
    {
        AffiliateOrderItem::factory()->create(['platform' => 'Shopee', 'order_id' => 'SO1', 'item_id' => 111, 'checkout_id' => 'SHC1']);
        AffiliateOrderItem::factory()->create(['platform' => 'TikTok', 'order_id' => 'TO1', 'item_id' => 222, 'checkout_id' => '']);
        AffiliateOrderItem::factory()->create([
            'platform' => 'ShopeeFood',
            'order_id' => 'SF1',
            'item_id' => null,
            'checkout_id' => 'SFC1',
            'shopee_food_line_key' => 'SFC1:P1',
        ]);

        $this->assertSame(3, AffiliateOrderItem::count());
        $this->assertSame('Shopee', AffiliateOrderItem::where('platform', 'Shopee')->first()->platform);
        $this->assertSame('TikTok', AffiliateOrderItem::where('platform', 'TikTok')->first()->platform);
        $this->assertSame('ShopeeFood', AffiliateOrderItem::where('platform', 'ShopeeFood')->first()->platform);
    }

    public function test_lazada_can_store_null_item_id_with_valid_business_key(): void
    {
        $row = AffiliateOrderItem::factory()->create([
            'order_id'       => '839912345678901',
            'item_id'        => null,
            'platform'       => 'Lazada',
            'checkout_id'    => '839912345678901',
            'lazada_line_key' => '839912345678901:839912345678902:6021831634002',
            'item_name'      => 'Son Kem',
        ]);

        $saved = $row->fresh();
        $this->assertNull($saved->item_id);
        $this->assertSame('Lazada', $saved->platform);
        $this->assertSame('839912345678901:839912345678902:6021831634002', $saved->lazada_line_key);
    }

    public function test_two_lazada_lines_share_numeric_sku_across_sub_orders(): void
    {
        // Same order + same numeric sku but different subOrderId -> distinct keys,
        // and item_id is NULL so the protected uk_platform_order_item is untouched.
        AffiliateOrderItem::factory()->create([
            'order_id' => 'O1', 'item_id' => null, 'platform' => 'Lazada',
            'checkout_id' => 'O1', 'lazada_line_key' => 'O1:S1:6021831634002',
        ]);
        AffiliateOrderItem::factory()->create([
            'order_id' => 'O1', 'item_id' => null, 'platform' => 'Lazada',
            'checkout_id' => 'O1', 'lazada_line_key' => 'O1:S2:6021831634002',
        ]);

        $this->assertSame(2, AffiliateOrderItem::where('platform', 'Lazada')->count());
    }

    public function test_lazada_duplicate_line_key_is_rejected(): void
    {
        $row = [
            'order_id' => 'O2', 'item_id' => null, 'platform' => 'Lazada',
            'checkout_id' => 'O2', 'lazada_line_key' => 'O2:S1:SKU1',
        ];

        AffiliateOrderItem::factory()->create($row);

        $this->expectException(QueryException::class);
        AffiliateOrderItem::factory()->create($row);
    }

    public function test_lazada_has_own_sync_timestamp_column(): void
    {
        $this->assertTrue(Schema::hasColumn('affiliate_order_items', 'lazada_line_key'));
        $this->assertTrue(Schema::hasColumn('affiliate_order_items', 'last_lazada_sync_at'));

        $row = AffiliateOrderItem::factory()->create([
            'platform' => 'Lazada',
            'order_id' => 'O3',
            'lazada_line_key' => 'O3:S1:SKU1',
            'last_lazada_sync_at' => now(),
        ]);

        $this->assertNotNull($row->fresh()->last_lazada_sync_at);
    }
}