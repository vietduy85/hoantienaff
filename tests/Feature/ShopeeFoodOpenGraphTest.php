<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeFoodOpenGraphTest extends TestCase
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

    // ─── 1. Success: <title> + og:image → name + real image ────────

    public function test_preview_uses_open_graph_name_and_image(): void
    {
        $this->fakeOpenGraph(
            'Bánh Tiêu Tân Định 1990 -  Rạch Bùng Binh (Không Chi Nhánh)',
            'https://down-test-vn.img.susercontent.com/store-photo.webp',
        );

        $link = $this->createLink('https://shopeefood.vn/u/og001');

        $this->assertSame('Bánh Tiêu Tân Định 1990 - Rạch Bùng Binh (Không Chi Nhánh)', $link->product_name);
        $this->assertSame('https://down-test-vn.img.susercontent.com/store-photo.webp', $link->product_image);
        $this->assertSame('shopeefood-open-graph', $link->data_source);
    }

    // ─── 2. No <title> tag → og:title is used ──────────────────────

    public function test_og_title_is_used_when_title_tag_missing(): void
    {
        Http::fake([
            'shopeefood.vn/u/og002*' => Http::response(
                $this->ogHtml('Tên từ og:title', 'https://img.example/photo.jpg', false, true),
                200,
            ),
        ]);

        $link = $this->createLink('https://shopeefood.vn/u/og002');

        $this->assertSame('Tên từ og:title', $link->product_name);
    }

    // ─── 3. Title present, no og:image → fallback image ────────────

    public function test_title_without_og_image_uses_placeholder_image(): void
    {
        $this->fakeOpenGraph('C&#225;fe &amp; Tr&#224;', null);

        $link = $this->createLink('https://shopeefood.vn/u/og003');

        $this->assertSame('Cáfe & Trà', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
        $this->assertSame('shopeefood-open-graph', $link->data_source);
    }

    // ─── 4. No title + no og:image → ShopeeFood + placeholder ──────

    public function test_no_title_and_no_image_falls_back(): void
    {
        Http::fake([
            'shopeefood.vn/u/og004*' => Http::response($this->ogHtml(null, null, false, false), 200),
        ]);

        $link = $this->createLink('https://shopeefood.vn/u/og004');

        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
        $this->assertSame('shopeefood-fallback', $link->data_source);
    }

    // ─── 5. Invalid og:image scheme → placeholder image ────────────

    public function test_invalid_og_image_scheme_falls_back(): void
    {
        $this->fakeOpenGraph('Quán Ngon', 'javascript:alert(1)');

        $link = $this->createLink('https://shopeefood.vn/u/og005');

        $this->assertSame('Quán Ngon', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
    }

    // ─── 6. HTTP failure does not break link creation ──────────────

    public function test_open_graph_http_error_falls_back_safely(): void
    {
        Http::fake([
            'shopeefood.vn/u/og006*' => Http::response('', 500),
        ]);

        $link = $this->createLink('https://shopeefood.vn/u/og006');

        $this->assertSame('failed', $link->status);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
        $this->assertSame('shopeefood-fallback', $link->data_source);
    }

    // ─── 7. Result is cached: second call does not hit HTTP ────────

    public function test_open_graph_result_is_cached(): void
    {
        $this->fakeOpenGraph('Quán Cache Ngon', 'https://img.example/cache.jpg');

        $service = app(\App\Services\ShopeeFood\ShopeeFoodOpenGraphService::class);

        $first = $service->resolveNameAndImage('https://shopeefood.vn/u/og007');
        $second = $service->resolveNameAndImage('https://shopeefood.vn/u/og007');

        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'shopeefood.vn/u/'))->count());

        $this->assertSame(['name' => 'Quán Cache Ngon', 'image' => 'https://img.example/cache.jpg'], $first);
        $this->assertSame(['name' => 'Quán Cache Ngon', 'image' => 'https://img.example/cache.jpg'], $second);

        $this->assertSame([
            'name'  => 'Quán Cache Ngon',
            'image' => 'https://img.example/cache.jpg',
        ], Cache::get('shopeefood:u:og007'));
    }

    // ─── 8. Cache TTL is 12 hours ──────────────────────────────────

    public function test_open_graph_cache_ttl_is_twelve_hours(): void
    {
        Cache::shouldReceive('get')->once()->with('shopeefood:u:ttl001')->andReturn(null);
        Cache::shouldReceive('put')->once()->with(
            'shopeefood:u:ttl001',
            ['name' => 'Quán TTL', 'image' => null],
            43200,
        );

        Http::fake([
            'shopeefood.vn/u/ttl001*' => Http::response($this->ogHtml('Quán TTL', null), 200),
        ]);

        $service = app(\App\Services\ShopeeFood\ShopeeFoodOpenGraphService::class);

        $this->assertSame(
            ['name' => 'Quán TTL', 'image' => null],
            $service->resolveNameAndImage('https://shopeefood.vn/u/ttl001'),
        );
    }

    // ─── 9. Affiliate URL is preserved after enrichment ────────────

    public function test_affiliate_url_is_preserved_with_open_graph_enrichment(): void
    {
        $this->fakeOpenGraph('Quán Giữ Nguyên', 'https://img.example/keep.jpg');

        $link = $this->createLink('https://shopeefood.vn/u/og009');

        $this->assertSame('Quán Giữ Nguyên', $link->product_name);
        $this->assertSame('https://img.example/keep.jpg', $link->product_image);

        $this->completeViaExtension($link, 'https://spf.shopee.vn/HASH_OG9');

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('https://spf.shopee.vn/HASH_OG9', $link->affiliate_url);
        $this->assertSame('Quán Giữ Nguyên', $link->product_name);
        $this->assertSame('https://img.example/keep.jpg', $link->product_image);
        $this->assertSame('shopeefood-open-graph', $link->data_source);
    }

    // ─── 10. Non /u/ URLs never hit the Open Graph endpoint ────────

    public function test_id_url_does_not_call_open_graph_endpoint(): void
    {
        Http::fake([
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'data' => [['name' => 'Quán Store']]],
                200,
            ),
        ]);

        $link = $this->createLink('https://shopeefood.vn/now-food/shop/1258133');

        $this->assertSame('Quán Store', $link->product_name);
        $this->assertSame('shopeefood-store-api', $link->data_source);

        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), 'shopeefood.vn/u/')));
    }

    // ─── 11. Non-shopeefood host never calls Open Graph ────────────

    public function test_non_shopeefood_host_does_not_call_open_graph(): void
    {
        Http::fake();

        $service = app(\App\Services\ShopeeFood\ShopeeFoodOpenGraphService::class);

        $this->assertNull($service->resolveNameAndImage('https://example.com/u/abc'));
        Http::assertNothingSent();
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
        Http::fake([
            'spf.shopee.vn/*' => Http::response('', 200),
        ]);

        $this->postJson('/api/extension/results?token=' . $this->extensionToken, [
            'results' => [
                ['id' => $link->id, 'affiliate_url' => $affiliateUrl, 'status' => 'completed'],
            ],
        ])->assertOk();
    }

    private function fakeOpenGraph(string $title, ?string $ogImage): void
    {
        Http::fake([
            'shopeefood.vn/u/*' => Http::response($this->ogHtml($title, $ogImage), 200),
        ]);
    }

    private function ogHtml(?string $title, ?string $ogImage, bool $withTitleTag = true, bool $withOgTitle = true): string
    {
        $titleTag = $withTitleTag && $title !== null ? '<title>' . $title . '</title>' : '';
        $ogTitle = $withOgTitle && $title !== null ? '<meta property="og:title" content="' . $title . '" />' : '';
        $ogImageTag = $ogImage !== null ? '<meta property="og:image" content="' . $ogImage . '" />' : '';

        return '<!doctype html><html><head><meta charset="utf-8">' . $titleTag . $ogTitle . $ogImageTag . '</head><body></body></html>';
    }
}