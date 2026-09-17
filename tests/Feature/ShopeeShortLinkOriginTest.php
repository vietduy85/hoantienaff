<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\UrlResolverService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class ShopeeShortLinkOriginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'tintuctonghop103',
        ]);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '17342330566');
        Setting::set('affiliate.direct.resolve_shortlink', 'true');
    }

    // ─── A. Full Shopee product URL → used directly, no over-resolve ──
    public function test_full_shopee_product_url_used_as_origin_link(): void
    {
        $url = 'https://shopee.vn/product/59917031/56759033748';

        $this->mock(UrlResolverService::class, function ($mock) use ($url) {
            $mock->shouldReceive('resolve')
                ->once()
                ->with($url)
                ->andReturn($url);
            $mock->shouldReceive('isShopeeLanding')
                ->once()
                ->with($url)
                ->andReturn(true);
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
        ]);

        $response->assertStatus(200);

        $link = LinkRequest::first();
        $this->assertNotNull($link);
        $this->assertSame(56759033748, $link->item_id);

        parse_str(parse_url($link->affiliate_url, PHP_URL_QUERY), $params);
        $this->assertSame('https://shopee.vn/product/59917031/56759033748', $params['origin_link']);
        $this->assertSame('17342330566', $params['affiliate_id']);
        $this->assertSame('tintuctonghop103', $params['sub_id']);
    }

    // ─── B. vn.shp.ee short link → origin_link is the canonical landing ──
    public function test_vn_shpee_short_link_uses_canonical_landing_as_origin(): void
    {
        $short = 'https://vn.shp.ee/yvL5woPp';
        $landing = 'https://shopee.vn/product/59917031/56759033748?d_id=2816c&uls_trackid=56lgp1gc00k1';

        $this->mock(UrlResolverService::class, function ($mock) use ($short, $landing) {
            $mock->shouldReceive('resolve')
                ->once()
                ->with($short)
                ->andReturn($landing);
            $mock->shouldReceive('isShopeeLanding')
                ->once()
                ->with($landing)
                ->andReturn(true);
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $short,
        ]);

        $response->assertStatus(200);

        $link = LinkRequest::first();
        $this->assertNotNull($link);
        $this->assertSame(56759033748, $link->item_id);

        parse_str(parse_url($link->affiliate_url, PHP_URL_QUERY), $params);
        $this->assertNotSame($short, $params['origin_link']);
        $this->assertStringNotContainsString('shp.ee', $params['origin_link']);
        $this->assertStringStartsWith('https://shopee.vn/product/59917031/56759033748', $params['origin_link']);
        $this->assertSame('17342330566', $params['affiliate_id']);
        $this->assertSame('tintuctonghop103', $params['sub_id']);
    }

    // ─── C. Resolver timeout / unresolved short link → clear error, NO affiliate URL ──
    public function test_resolver_failure_returns_error_and_no_affiliate_url(): void
    {
        $short = 'https://vn.shp.ee/yvL5woPp';
        $message = 'Không lấy được sản phẩm Shopee từ link rút gọn. Vui lòng thử lại.';

        $this->mock(UrlResolverService::class, function ($mock) use ($short) {
            $mock->shouldReceive('resolve')
                ->once()
                ->with($short)
                ->andReturn(null);
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $short,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error' => $message,
        ]);

        $this->assertDatabaseHas('link_requests', [
            'user_id' => $this->user->id,
            'status' => 'failed',
            'affiliate_url' => null,
            'notes' => $message,
        ]);
    }

    // ─── D. Resolver returns non-Shopee host → error, NO affiliate URL ──
    public function test_resolver_to_non_shopee_host_returns_error(): void
    {
        $short = 'https://s.shp.ee/abc';
        $evil = 'https://evil.example/x';

        $this->mock(UrlResolverService::class, function ($mock) use ($short, $evil) {
            $mock->shouldReceive('resolve')
                ->once()
                ->with($short)
                ->andReturn($evil);
            $mock->shouldReceive('isShopeeLanding')
                ->once()
                ->with($evil)
                ->andReturn(false);
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $short,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        $this->assertDatabaseHas('link_requests', [
            'user_id' => $this->user->id,
            'status' => 'failed',
            'affiliate_url' => null,
        ]);
    }

    // ─── E. CSRF 419 never creates a LinkRequest → retry won't duplicate ──
    public function test_csrf_token_mismatch_creates_no_link_request(): void
    {
        $request = Request::create('/link-requests', 'POST', ['original_url' => 'https://shopee.vn/product/1/2'], [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = app(ExceptionHandler::class)->render(
            $request,
            new TokenMismatchException('CSRF token mismatch.')
        );

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame(0, LinkRequest::count());

        $this->mock(UrlResolverService::class, function ($mock) {
            $mock->shouldReceive('resolve')
                ->once()
                ->andReturn('https://shopee.vn/product/1/2');
            $mock->shouldReceive('isShopeeLanding')
                ->once()
                ->andReturn(true);
        });

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://shopee.vn/product/1/2',
        ])->assertStatus(200);

        $this->assertSame(1, LinkRequest::count());
    }
}
