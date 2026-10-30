<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Services\ShopeeFashionAlertService;
use App\Services\ShopeeOrderCommissionRateFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeOrderCommissionRateFillerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['status' => 'error'], 200)]);
    }

    public function test_cache_rate_matching_actual_commission_is_exact_and_fashion_alert_fires(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, ['total_order_commission' => 11000]); // 11% of 100k
        $this->cache($order->item_id, 0.07, 0.04);

        $result = app(ShopeeOrderCommissionRateFiller::class)->fill();

        $this->assertSame(1, $result['applied']);
        $fresh = $order->fresh();
        $this->assertSame(7.0, (float) $fresh->shopee_commission_rate);
        $this->assertSame(4.0, (float) $fresh->seller_commission_rate);
        $this->assertSame('cache', $fresh->commission_rate_source);
        $this->assertSame('exact', $fresh->commission_rate_confidence);
        $this->assertSame(1, app(ShopeeFashionAlertService::class)->unseenCount($user));
    }

    public function test_listed_rate_above_actual_is_estimate_but_still_triggers_alert(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, ['total_order_commission' => 9000]); // actual 9%
        $this->cache($order->item_id, 0.07, 0.05);                          // listed 12%

        app(ShopeeOrderCommissionRateFiller::class)->fill();

        $this->assertSame('estimate', $order->fresh()->commission_rate_confidence);
        $this->assertSame(7.0, (float) $order->fresh()->shopee_commission_rate);
        $this->assertSame(1, app(ShopeeFashionAlertService::class)->unseenCount($user));
    }

    public function test_candidate_below_actual_commission_is_kept_only_as_estimate(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, ['total_order_commission' => 11000]);
        $this->cache($order->item_id, 0.025, 0.0); // 2.5% < 11%

        $result = app(ShopeeOrderCommissionRateFiller::class)->fill();

        $this->assertSame(1, $result['applied']);
        $this->assertSame(2.5, (float) $order->fresh()->shopee_commission_rate);
        $this->assertSame('estimate', $order->fresh()->commission_rate_confidence);
    }

    public function test_zero_amount_order_gets_zero_rate_and_is_refilled_when_amount_appears(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, ['order_amount' => 0, 'total_order_commission' => 0]);

        app(ShopeeOrderCommissionRateFiller::class)->fill();
        $this->assertSame('zero-amount', $order->fresh()->commission_rate_source);
        $this->assertSame(0.0, (float) $order->fresh()->shopee_commission_rate);

        $order->update(['order_amount' => 100000, 'total_order_commission' => 7000, 'commission_rate_checked_at' => null]);
        $this->cache($order->item_id, 0.07, 0.0);
        app(ShopeeOrderCommissionRateFiller::class)->fill();
        $this->assertSame(7.0, (float) $order->fresh()->shopee_commission_rate);
    }

    public function test_admin_always_sees_at_least_one_alert(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('Admin');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->assertSame(1, app(ShopeeFashionAlertService::class)->unseenCount($admin));
    }

    public function test_existing_csv_rates_and_orders_before_cutoff_are_untouched(): void
    {
        $user = User::factory()->create();
        $csv = $this->order($user, ['shopee_commission_rate' => 2.5, 'total_order_commission' => 2500]);
        $old = $this->order($user, ['ordered_at' => '2026-10-24 23:59:59', 'total_order_commission' => 7000]);
        $this->cache($csv->item_id, 0.07, 0.0);
        $this->cache($old->item_id, 0.07, 0.0);

        $result = app(ShopeeOrderCommissionRateFiller::class)->fill();

        $this->assertSame(0, $result['applied']);
        $this->assertSame(2.5, (float) $csv->fresh()->shopee_commission_rate);
        $this->assertNull($csv->fresh()->commission_rate_source);
        $this->assertSame(0.0, (float) $old->fresh()->shopee_commission_rate);
    }

    public function test_estimate_is_upgraded_to_exact_but_never_downgraded(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, ['total_order_commission' => 9000]);
        $this->cache($order->item_id, 0.07, 0.05);
        app(ShopeeOrderCommissionRateFiller::class)->fill();
        $this->assertSame('estimate', $order->fresh()->commission_rate_confidence);

        DB::table('affiliate_order_items')->where('id', $order->id)->update(['commission_rate_checked_at' => null]);
        DB::table('affiliate_cache')->where('item_id', $order->item_id)->update([
            'shopee_commission_rate' => 0.07, 'seller_commission_rate' => 0.02,
        ]);
        app(ShopeeOrderCommissionRateFiller::class)->fill();

        $fresh = $order->fresh();
        $this->assertSame('exact', $fresh->commission_rate_confidence);
        $this->assertSame(2.0, (float) $fresh->seller_commission_rate);
    }

    public function test_dry_run_does_not_write(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, ['total_order_commission' => 7000]);
        $this->cache($order->item_id, 0.07, 0.0);

        $result = app(ShopeeOrderCommissionRateFiller::class)->fill(apply: false);

        $this->assertSame(0, $result['applied']);
        $this->assertSame('propose', $result['rows'][0]['action']);
        $this->assertSame(0.0, (float) $order->fresh()->shopee_commission_rate);
    }

    private function order(User $user, array $overrides = []): AffiliateOrderItem
    {
        return AffiliateOrderItem::factory()->create(array_merge([
            'platform' => 'Shopee',
            'user_id' => $user->id,
            'item_id' => random_int(100000, 999999999),
            'ordered_at' => '2026-10-26 10:00:00',
            'order_amount' => 100000,
            'total_order_commission' => 7000,
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
            'affiliate_status' => 'Hoàn thành',
        ], $overrides));
    }

    private function cache(int $itemId, float $shopee, float $seller): void
    {
        DB::table('affiliate_cache')->insert([
            'item_id' => $itemId,
            'cache_date' => '2026-10-26',
            'platform' => 'Shopee',
            'shopee_commission_rate' => $shopee,
            'seller_commission_rate' => $seller,
            'commission_rate_source' => 'api_or_db',
            'commission_rate_status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
