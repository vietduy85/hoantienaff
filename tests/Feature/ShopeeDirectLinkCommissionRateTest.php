<?php

namespace Tests\Feature;

use App\Models\AffiliateCache;
use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeDirectLinkCommissionRateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['username' => 'commission-probe']);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '17342330566');
        Setting::set('affiliate.direct.resolve_shortlink', 'true');

        config([
            'services.addlivetag.api_key' => 'test-key',
            'services.addlivetag.base_url' => 'https://data.addlivetag.com',
            'app.affiliate_timing' => false,
        ]);
    }

    private function productDataResponse(int $itemId, int $shopId): array
    {
        return [
            'status' => 'success',
            'productInfo' => [
                'itemId' => $itemId,
                'shopId' => $shopId,
                'productName' => 'Probe Product',
                'price' => 100000,
                'commission' => 4000,
                'sellerComFinal' => 8000,
                'shopeeComFinal' => 4000,
                'shopeeRate' => 0.04,
                'sellerRate' => 0.08,
                'shopeeRateSource' => 'api',
                'productLink' => 'https://shopee.vn/product/'.$shopId.'/'.$itemId,
                'dataSource' => 'api',
            ],
        ];
    }

    private function postLink(int $itemId, int $shopId): LinkRequest
    {
        $url = 'https://shopee.vn/Probe-i.'.$shopId.'.'.$itemId
            .'?extraParams=%7B%22display_model_id%22%3A157499877191%7D';

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
            'fast_mode' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $link = LinkRequest::findOrFail($response->json('request_id'));
        $this->assertNotEmpty($link->affiliate_url);

        return $link->refresh();
    }

    private function makeCache(int $itemId, array $overrides = []): AffiliateCache
    {
        return AffiliateCache::create(array_merge([
            'item_id' => $itemId,
            'platform' => 'Shopee',
            'cache_date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'shop_id' => 37528463,
            'product_name' => 'Cached Product',
            'product_price' => 100000,
            'estimated_cashback' => 4000,
            'user_estimated_cashback' => 2000,
            'cashback_rate' => 0.50,
            'affiliate_url' => 'https://s.shopee.vn/cached',
        ], $overrides));
    }

    public function test_shopee_normal_mode_completes_and_persists_commission_rate_snapshot(): void
    {
        Http::fake([
            '*product-data.php*' => Http::response($this->productDataResponse(5926546426, 37528463), 200),
        ]);

        $link = $this->postLink(5926546426, 37528463);

        $this->assertSame('completed', $link->status);
        $this->assertSame('Probe Product', $link->product_name);
        $this->assertSame('0.04', $link->shopee_commission_rate_at_create);
        $this->assertSame('0.08', $link->seller_commission_rate_at_create);
        $this->assertSame('product-data', $link->commission_rate_source);
        $this->assertSame('available', $link->commission_rate_status);
        $this->assertNotNull($link->commission_rate_captured_at);

        $cache = AffiliateCache::where('item_id', 5926546426)->first();
        $this->assertNotNull($cache);
        $this->assertSame('Shopee', $cache->platform);
        $this->assertSame('0.0400', $cache->shopee_commission_rate);
        $this->assertSame('0.0800', $cache->seller_commission_rate);
        $this->assertSame('available', $cache->commission_rate_status);
    }

    public function test_shopee_normal_mode_finishes_link_even_when_product_data_fails(): void
    {
        Http::fake([
            '*product-data.php*' => Http::response(['status' => 'error'], 200),
        ]);

        $link = $this->postLink(5926546427, 37528463);

        $this->assertSame('completed', $link->status);
        $this->assertNotEmpty($link->affiliate_url);
        $this->assertNull($link->product_name);
    }

    public function test_cache_hit_with_both_rates_copies_snapshot_to_link_request(): void
    {
        Http::fake();

        $this->makeCache(5926546430, [
            'shopee_commission_rate' => 0.05,
            'seller_commission_rate' => 0.09,
            'commission_rate_source' => 'api_or_db',
            'commission_rate_status' => 'available',
            'commission_rate_checked_at' => now(),
        ]);

        $link = $this->postLink(5926546430, 37528463);

        $this->assertSame('completed', $link->status);
        $this->assertSame('0.05', $link->shopee_commission_rate_at_create);
        $this->assertSame('0.09', $link->seller_commission_rate_at_create);
        $this->assertSame('product-data-cache', $link->commission_rate_source);
        $this->assertSame('available', $link->commission_rate_status);
        $this->assertNotNull($link->commission_rate_captured_at);

        Http::assertNothingSent();
    }

    public function test_cache_hit_with_rate_but_missing_metadata_keeps_rate_and_fills_metadata(): void
    {
        Http::fake();

        $this->makeCache(5926546431, [
            'shopee_commission_rate' => 0.06,
            'seller_commission_rate' => null,
            'commission_rate_source' => null,
            'commission_rate_status' => null,
            'commission_rate_checked_at' => null,
        ]);

        $link = $this->postLink(5926546431, 37528463);

        $this->assertSame('0.06', $link->shopee_commission_rate_at_create);
        $this->assertNull($link->seller_commission_rate_at_create);
        $this->assertSame('product-data-cache', $link->commission_rate_source);
        $this->assertSame('available', $link->commission_rate_status);
        $this->assertNotNull($link->commission_rate_captured_at);

        Http::assertNothingSent();
    }

    public function test_cache_hit_without_rates_supplements_from_product_data(): void
    {
        Http::fake([
            '*product-data.php*' => Http::response($this->productDataResponse(5926546432, 37528463), 200),
        ]);

        $this->makeCache(5926546432, [
            'shopee_commission_rate' => null,
            'seller_commission_rate' => null,
        ]);

        $link = $this->postLink(5926546432, 37528463);

        $this->assertSame('completed', $link->status);
        $this->assertSame('0.04', $link->shopee_commission_rate_at_create);
        $this->assertSame('0.08', $link->seller_commission_rate_at_create);
        $this->assertSame('product-data', $link->commission_rate_source);
        $this->assertSame('available', $link->commission_rate_status);
        $this->assertNotEmpty($link->affiliate_url);

        $cache = AffiliateCache::where('item_id', 5926546432)->firstOrFail();
        $this->assertSame('Shopee', $cache->platform);
        $this->assertSame('0.0400', $cache->shopee_commission_rate);

        $productDataCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'product-data.php'))
            ->count();
        $this->assertSame(1, $productDataCalls);
    }

    public function test_legacy_null_platform_cache_rates_are_not_trusted_without_verified_fetch(): void
    {
        Http::fake([
            '*product-data.php*' => Http::response($this->productDataResponse(5926546433, 37528463), 200),
        ]);

        $this->makeCache(5926546433, [
            'platform' => null,
            'shopee_commission_rate' => 0.09,
            'seller_commission_rate' => 0.09,
            'commission_rate_source' => 'api_or_db',
            'commission_rate_status' => 'available',
        ]);

        $link = $this->postLink(5926546433, 37528463);

        $this->assertSame('0.04', $link->shopee_commission_rate_at_create);
        $this->assertSame('0.08', $link->seller_commission_rate_at_create);
        $this->assertSame('product-data', $link->commission_rate_source);

        $cache = AffiliateCache::where('item_id', 5926546433)->firstOrFail();
        $this->assertSame('Shopee', $cache->platform);
        $this->assertSame('0.0400', $cache->shopee_commission_rate);

        $productDataCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'product-data.php'))
            ->count();
        $this->assertSame(1, $productDataCalls);
    }

    public function test_zero_cache_rate_is_preserved_but_not_treated_as_valid(): void
    {
        Http::fake([
            '*product-data.php*' => Http::response(['status' => 'error'], 200),
        ]);

        $this->makeCache(5926546434, [
            'shopee_commission_rate' => 0,
            'seller_commission_rate' => 0,
            'commission_rate_source' => 'api_or_db',
            'commission_rate_status' => 'available',
        ]);

        $link = $this->postLink(5926546434, 37528463);

        $this->assertSame('0.00', $link->shopee_commission_rate_at_create);
        $this->assertSame('0.00', $link->seller_commission_rate_at_create);
        $this->assertSame('product-data-cache', $link->commission_rate_source);
        $this->assertSame('unverified', $link->commission_rate_status);

        $productDataCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'product-data.php'))
            ->count();
        $this->assertSame(1, $productDataCalls);
    }

    public function test_shopee_normal_mode_handles_legacy_placeholder_cache_row_without_duplicate_key(): void
    {
        AffiliateCache::create([
            'item_id' => 5926546428,
            'platform' => null,
            'cache_date' => now('Asia/Ho_Chi_Minh')->toDateString(),
        ]);

        Http::fake([
            '*product-data.php*' => Http::response($this->productDataResponse(5926546428, 37528463), 200),
        ]);

        $link = $this->postLink(5926546428, 37528463);

        $this->assertSame('completed', $link->status);

        $rows = AffiliateCache::where('item_id', 5926546428)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Shopee', $rows->first()->platform);
        $this->assertSame('0.0400', $rows->first()->shopee_commission_rate);
    }
}
