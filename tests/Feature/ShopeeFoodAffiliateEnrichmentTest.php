<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeFoodAffiliateEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private string $extensionToken = 'test-extension-token';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.affiliate_extension.token' => $this->extensionToken]);

        Cache::flush();

        $this->user = User::factory()->create([
            'username' => 'test_user',
        ]);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.admin.strategy', 'extension');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    // ─── CASE 1 — short link resolves to a real ShopeeFood shop ────

    public function test_short_link_is_enriched_with_store_name(): void
    {
        $this->fakeResolutionChain(757850, 'Tên cửa hàng test');

        $link = $this->createLink('https://shopeefood.vn/u/5vsebc');

        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertSame('shopeefood-fallback', $link->data_source);

        $this->completeViaExtension($link, 'https://spf.shopee.vn/TESTHASH_A1');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://spf.shopee.vn/TESTHASH_A1', $link->affiliate_url);
        $this->assertSame('Tên cửa hàng test', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
        $this->assertSame('shopeefood-store-api', $link->data_source);
    }

    // ─── CASE 2 — spf resolve returns HTTP 500 ─────────────────────

    public function test_resolve_http_500_falls_back_safely(): void
    {
        Http::fake([
            'spf.shopee.vn/*' => Http::response('', 500),
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'data' => [['name' => 'Không đúng']]],
                200,
            ),
        ]);

        $link = $this->createLink('https://shopeefood.vn/u/5vsebc');
        $this->completeViaExtension($link, 'https://spf.shopee.vn/TESTHASH_A2');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://spf.shopee.vn/TESTHASH_A2', $link->affiliate_url);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertSame('shopeefood-fallback', $link->data_source);
        $this->assertNotSentToStore();
    }

    // ─── CASE 3 — spf resolves to a non-StoreeeFood URL ────────────

    public function test_resolve_to_non_shopeefood_falls_back(): void
    {
        Http::fake([
            'spf.shopee.vn/*' => Http::response('', 302, ['Location' => 'https://shopee.vn/item/1']),
            'shopee.vn/item/1' => Http::response('', 200),
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'data' => [['name' => 'Không đúng']]],
                200,
            ),
        ]);

        $link = $this->createLink('https://shopeefood.vn/u/5vsebc');
        $this->completeViaExtension($link, 'https://spf.shopee.vn/TESTHASH_A3');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://spf.shopee.vn/TESTHASH_A3', $link->affiliate_url);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertSame('shopeefood-fallback', $link->data_source);
        $this->assertNotSentToStore();
    }

    // ─── CASE 4 — final URL has no restaurant_id ───────────────────

    public function test_resolve_to_url_without_restaurant_id_falls_back(): void
    {
        Http::fake([
            'spf.shopee.vn/*' => Http::response('', 302, ['Location' => 'https://shopeefood.vn/']),
            'shopeefood.vn/*' => Http::response('', 200),
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'data' => [['name' => 'Không đúng']]],
                200,
            ),
        ]);

        $link = $this->createLink('https://shopeefood.vn/u/5vsebc');
        $this->completeViaExtension($link, 'https://spf.shopee.vn/TESTHASH_A4');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://spf.shopee.vn/TESTHASH_A4', $link->affiliate_url);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertSame('shopeefood-fallback', $link->data_source);
        $this->assertNotSentToStore();
    }

    // ─── CASE 5 — Store API returns empty data ─────────────────────

    public function test_store_api_empty_data_falls_back(): void
    {
        Http::fake([
            'spf.shopee.vn/*' => Http::response('', 302, ['Location' => 'https://shopeefood.vn/now-food/shop/757852']),
            'shopeefood.vn/now-food/shop/757852*' => Http::response('', 200),
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'count' => 0, 'data' => []],
                200,
            ),
        ]);

        $link = $this->createLink('https://shopeefood.vn/u/5vsebc');
        $this->completeViaExtension($link, 'https://spf.shopee.vn/TESTHASH_A5');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://spf.shopee.vn/TESTHASH_A5', $link->affiliate_url);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertSame('shopeefood-fallback', $link->data_source);
    }

    // ─── CASE 6 — full URL with restaurant_id flow unchanged ───────

    public function test_full_url_with_id_flow_is_unchanged(): void
    {
        $this->fakeResolutionChain(757851, 'Cửa hàng gốc');

        $link = $this->createLink('https://shopeefood.vn/now-food/shop/757851');

        $this->assertSame('Cửa hàng gốc', $link->product_name);
        $this->assertSame('shopeefood-store-api', $link->data_source);

        $this->completeViaExtension($link, 'https://spf.shopee.vn/TESTHASH_A6');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://spf.shopee.vn/TESTHASH_A6', $link->affiliate_url);
        $this->assertSame('Cửa hàng gốc', $link->product_name);
    }

    // ─── CASE 7a — enrichment skipped for non-spf affiliate URL ────

    public function test_non_spf_affiliate_url_is_not_resolved(): void
    {
        Http::fake([
            's.shopee.vn/*' => Http::response('', 200),
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'data' => [['name' => 'Không đúng']]],
                200,
            ),
        ]);

        $link = $this->createLink('https://shopeefood.vn/u/5vsebc');
        $this->completeViaExtension($link, 'https://s.shopee.vn/an_redir?origin_link=x');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://s.shopee.vn/an_redir?origin_link=x', $link->affiliate_url);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertNotSentToSpf();
        $this->assertNotSentToStore();
    }

    // ─── CASE 7b — Shopee platform is never enriched ───────────────

    public function test_shopee_platform_link_is_not_enriched(): void
    {
        Http::fake([
            'spf.shopee.vn/*' => Http::response('', 302, ['Location' => 'https://shopeefood.vn/now-food/shop/757850']),
            'shopeefood.vn/now-food/shop/757850*' => Http::response('', 200),
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'data' => [['name' => 'Không đúng']]],
                200,
            ),
        ]);

        $link = LinkRequest::create([
            'user_id'      => $this->user->id,
            'original_url' => 'https://shopeefood.vn/u/5vsebc',
            'platform'     => 'Shopee',
            'status'       => 'pending',
        ]);

        $this->completeViaExtension($link, 'https://spf.shopee.vn/TESTHASH_A7');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://spf.shopee.vn/TESTHASH_A7', $link->affiliate_url);
        $this->assertNull($link->product_name);
        $this->assertNotSentToSpf();
        $this->assertNotSentToStore();
    }

    // ─── CASE 8 — spf hash mapping is cached (TTL 12h) ─────────────

    public function test_spf_resolution_is_cached(): void
    {
        $this->fakeResolutionChain(757853, 'Quán cache');

        $first = $this->createLink('https://shopeefood.vn/u/5vsebc');
        $this->completeViaExtension($first, 'https://spf.shopee.vn/TESTHASH_A8');

        $second = $this->createLink('https://shopeefood.vn/u/abc/5vsebc');
        $this->completeViaExtension($second, 'https://spf.shopee.vn/TESTHASH_A8');

        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'spf.shopee.vn'))->count());
        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'store.php'))->count());

        $this->assertSame('757853', Cache::get('shopeefood:spf:TESTHASH_A8'));
        $this->assertSame('Quán cache', $second->product_name);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function createLink(string $url): LinkRequest
    {
        $this->actingAs($this->user)
            ->postJson('/link-requests', ['original_url' => $url])
            ->assertOk();

        return LinkRequest::latest()->first();
    }

    private function completeViaExtension(LinkRequest $link, string $affiliateUrl): void
    {
        $this->postJson('/api/extension/results?token=' . $this->extensionToken, [
            'results' => [
                ['id' => $link->id, 'affiliate_url' => $affiliateUrl, 'status' => 'completed'],
            ],
        ])->assertOk();

        $link->refresh();
    }

    private function fakeResolutionChain(int $restaurantId, string $name): void
    {
        Http::fake([
            'spf.shopee.vn/*' => Http::response('', 302, [
                'Location' => 'https://shopeefood.vn/now-food/shop/' . $restaurantId,
            ]),
            'shopeefood.vn/now-food/shop/' . $restaurantId . '*' => Http::response('', 200),
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'data' => [['name' => $name]]],
                200,
            ),
        ]);
    }

    private function assertNotSentToSpf(): void
    {
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), 'spf.shopee.vn')));
    }

    private function assertNotSentToStore(): void
    {
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), 'store.php')));
    }
}