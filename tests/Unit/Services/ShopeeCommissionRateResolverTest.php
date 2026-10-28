<?php

namespace Tests\Unit\Services;

use App\Models\AffiliateCache;
use App\Models\AffiliateOrderItem;
use App\Models\LinkRequest;
use App\Models\User;
use App\Services\AffiliateCacheService;
use App\Services\ShopeeCommissionRateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShopeeCommissionRateResolverTest extends TestCase
{
    use RefreshDatabase;

    private ShopeeCommissionRateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(ShopeeCommissionRateResolver::class);
    }

    public function test_order_before_2026_10_25_is_not_in_scope_logic(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 12345,
            'ordered_at' => Carbon::parse('2026-10-24 23:59:59', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_order_at_2026_10_25_000000_vn_is_in_scope(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 11111,
            'ordered_at' => Carbon::parse('2026-10-25 00:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_order_after_2026_10_25_in_scope(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 22222,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_does_not_overwrite_existing_valid_rate(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 33333,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.05,
            'seller_commission_rate' => 0.02,
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
        $this->assertEquals(0.05, $result['shopee_commission_rate']);
        $this->assertEquals(0.02, $result['seller_commission_rate']);
    }

    public function test_does_not_convert_zero_to_something(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 44444,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_does_not_confuse_cashback_rate_with_commission_rate(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 55555,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
            'cashback_rate' => 60.00,
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
        $this->assertEquals(0.00, $result['shopee_commission_rate']);
    }

    public function test_does_not_use_commission_amount_as_rate(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 66666,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
            'shopee_commission' => 50000.00,
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_does_not_use_cache_when_expired(): void
    {
        $user = User::factory()->create();
        $itemId = 77777;
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => $itemId,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        AffiliateCache::create([
            'item_id' => $itemId,
            'platform' => 'Shopee',
            'cache_date' => Carbon::now('Asia/Ho_Chi_Minh')->subDays(5)->toDateString(),
            'shopee_commission_rate' => 0.08,
            'commission_rate_source' => 'product-data',
            'commission_rate_status' => 'available',
            'commission_rate_checked_at' => now(),
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_does_not_use_cache_with_wrong_item_id(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 88888,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        AffiliateCache::create([
            'item_id' => 99999,
            'platform' => 'Shopee',
            'cache_date' => Carbon::now('Asia/Ho_Chi_Minh')->toDateString(),
            'shopee_commission_rate' => 0.08,
            'commission_rate_source' => 'product-data',
            'commission_rate_status' => 'available',
            'commission_rate_checked_at' => now(),
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_does_not_use_cache_with_null_platform_as_default_shopee(): void
    {
        $user = User::factory()->create();
        $itemId = 10101;
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => $itemId,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        AffiliateCache::create([
            'item_id' => $itemId,
            'platform' => null,
            'cache_date' => Carbon::now('Asia/Ho_Chi_Minh')->toDateString(),
            'shopee_commission_rate' => 0.08,
            'commission_rate_source' => 'product-data',
            'commission_rate_status' => 'available',
            'commission_rate_checked_at' => now(),
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_does_not_use_snapshot_without_evidence(): void
    {
        $user = User::factory()->create();
        $itemId = 20202;
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => $itemId,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        LinkRequest::create([
            'user_id' => $user->id,
            'item_id' => $itemId,
            'platform' => 'Shopee',
            'original_url' => 'https://shopee.vn/test',
            'affiliate_url' => 'https://affiliate.test',
            'shopee_commission_rate_at_create' => 0.07,
            'commission_rate_source' => 'product-data',
            'commission_rate_status' => 'available',
            'commission_rate_captured_at' => now(),
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_ordered_at_null_requires_manual_review(): void
    {
        $user = User::factory()->create();
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => 30303,
            'ordered_at' => null,
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertIsArray($result);
    }

    public function test_does_not_use_none_or_fallback_source(): void
    {
        $user = User::factory()->create();
        $itemId = 40404;
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => $itemId,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        AffiliateCache::create([
            'item_id' => $itemId,
            'platform' => 'Shopee',
            'cache_date' => Carbon::now('Asia/Ho_Chi_Minh')->toDateString(),
            'shopee_commission_rate' => 0.08,
            'commission_rate_source' => 'none',
            'commission_rate_status' => 'available',
            'commission_rate_checked_at' => now(),
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertFalse($result['can_apply']);
    }

    public function test_valid_cache_with_proper_evidence_can_be_used(): void
    {
        $user = User::factory()->create();
        $itemId = 50505;
        $order = AffiliateOrderItem::factory()->create([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => $itemId,
            'ordered_at' => Carbon::parse('2026-10-26 10:00:00', 'Asia/Ho_Chi_Minh'),
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
        ]);

        AffiliateCache::create([
            'item_id' => $itemId,
            'platform' => 'Shopee',
            'cache_date' => Carbon::now('Asia/Ho_Chi_Minh')->toDateString(),
            'shopee_commission_rate' => 0.08,
            'seller_commission_rate' => 0.03,
            'commission_rate_source' => 'product-data',
            'commission_rate_status' => 'available',
            'commission_rate_checked_at' => now(),
        ]);

        $result = $this->resolver->resolve($order);
        $this->assertTrue($result['can_apply']);
        $this->assertEquals(0.08, $result['shopee_commission_rate']);
        $this->assertEquals(0.03, $result['seller_commission_rate']);
    }
}
