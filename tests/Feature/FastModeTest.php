<?php

namespace Tests\Feature;

use App\Models\AffiliateCache;
use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AffiliateCacheService;
use App\Services\ProductDataService;
use App\Services\UrlResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chế độ nhanh (Fast Mode) cho Shopee.
 *
 * Cơ chế quan trọng của bộ test này:
 *  `MakesHttpRequests::call()` gọi `$kernel->terminate($request, $response)`
 *  ngay sau khi xử lý xong, trong khi `Dispatcher::dispatchAfterResponse()`
 *  chỉ *đăng ký* terminating callback. Hệ quả: closure afterResponse CHẠY ĐỒNG
 *  BỘ trong chính lời gọi HTTP của test.
 *
 *  Nhờ vậy mọi lời gọi ProductData đều xảy ra trước khi test kết thúc và bị
 *  Mockery bắt được:
 *   - Test A0 chứng minh Normal Mode VẪN gọi ProductData => harness có tác dụng.
 *   - Các test Fast Mode dùng `shouldNotReceive('getByUrl')`; nếu Fast Mode lỡ
 *     dispatch closure, closure sẽ chạy và test FAIL ngay.
 *
 *  KHÔNG được gọi `app()->terminate()` thủ công: `Application::terminate()`
 *  KHÔNG xoá danh sách callback, nên gọi thêm sẽ chạy lại closure lần nữa
 *  (ProductData bị đếm 2 lần => `once()` fail).
 */
class FastModeTest extends TestCase
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

    private function productDataPayload(): array
    {
        return [
            'success'           => true,
            'item_id'           => 56759033748,
            'shop_id'           => 59917031,
            'product_name'      => 'Sản phẩm từ ProductData API',
            'product_price'     => 99000,
            'commission'        => 4000,
            'seller_commission' => 2500,
            'shopee_commission' => 1500,
            'rating'            => 4.8,
            'product_image'     => 'https://example.test/img.jpg',
            'product_link'      => 'https://shopee.vn/product/59917031/56759033748',
            'shop_name'         => 'Shop từ API',
            'sales'             => 1234,
            'is_xtra'           => false,
            'data_source'       => 'api',
        ];
    }

    private function seedCacheHit(int $itemId = 56759033748): void
    {
        AffiliateCache::create([
            'item_id'                => $itemId,
            'cache_date'             => now('Asia/Ho_Chi_Minh')->toDateString(),
            'product_name'           => 'Sản phẩm cached',
            'product_price'          => 50000,
            'estimated_cashback'     => 1000,
            'user_estimated_cashback' => 500,
            'cashback_rate'          => 5,
            'shop_id'                => 59917031,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // A0. HARNESS CHECK: Normal Mode VẪN gọi ProductData.
    //     Nếu test này pass, các test "Fast Mode không gọi" có ý nghĩa.
    // ─────────────────────────────────────────────────────────────
    public function test_harness_normal_mode_still_calls_productdata(): void
    {
        $url = 'https://shopee.vn/product/59917031/56759033748';

        $this->mock(UrlResolverService::class, function ($mock) use ($url) {
            $mock->shouldReceive('resolve')->once()->andReturn($url);
            $mock->shouldReceive('isShopeeLanding')->once()->andReturn(true);
        });

        $this->mock(ProductDataService::class, function ($mock) use ($url) {
            $mock->shouldReceive('getByUrl')
                ->once()
                ->with($url)
                ->andReturn($this->productDataPayload());
        });

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
        ])->assertStatus(200);

        $this->assertDatabaseHas('link_requests', [
            'status'      => 'completed',
            'item_id'     => 56759033748,
            'data_source' => 'api',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // A. Fast Mode + URL sản phẩm Shopee đầy đủ
    // ─────────────────────────────────────────────────────────────
    public function test_fast_mode_creates_link_without_productdata(): void
    {
        $url = 'https://shopee.vn/product/59917031/56759033748';

        $this->mock(UrlResolverService::class, function ($mock) use ($url) {
            $mock->shouldReceive('resolve')->once()->with($url)->andReturn($url);
            $mock->shouldReceive('isShopeeLanding')->once()->with($url)->andReturn(true);
        });

        $this->mock(ProductDataService::class, function ($mock) {
            $mock->shouldNotReceive('getByUrl');
        });

        // Fast Mode: extractItemId (thuần string parsing) vẫn chạy thật,
        // nhưng cấm đọc/ghi affiliate_cache.
        $this->partialMock(AffiliateCacheService::class, function ($mock) {
            $mock->shouldNotReceive('get');
            $mock->shouldNotReceive('put');
            $mock->shouldNotReceive('logMiss');
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
            'fast_mode'    => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success'          => true,
            'fast_mode'        => true,
            'status'           => 'completed',
            'item_id'          => 56759033748,
            'shopeedirect_url' => $url,
        ]);

        $link = LinkRequest::first();
        $this->assertNotNull($link);
        $this->assertSame('completed', $link->status);
        $this->assertSame(56759033748, $link->item_id);
        $this->assertSame($url, $link->product_link);
        $this->assertStringContainsString('affiliate_id=17342330566', $link->affiliate_url);

        // KHÔNG có dữ liệu enrichment nào.
        $this->assertNull($link->product_name);
        $this->assertNull($link->product_price);
        $this->assertNull($link->user_estimated_cashback);
        $this->assertNull($link->data_source);
    }

    // ─────────────────────────────────────────────────────────────
    // B. Fast Mode + short link: resolver VẪN dùng, origin_link là URL
    //    sản phẩm thật (không phải short link).
    // ─────────────────────────────────────────────────────────────
    public function test_fast_mode_short_link_uses_resolver_and_real_product_url(): void
    {
        $short = 'https://vn.shp.ee/yvL5woPp';
        $landing = 'https://shopee.vn/product/59917031/56759033748?d_id=2816c&uls_trackid=56lgp1gc00k1';

        $this->mock(UrlResolverService::class, function ($mock) use ($short, $landing) {
            $mock->shouldReceive('resolve')->once()->with($short)->andReturn($landing);
            $mock->shouldReceive('isShopeeLanding')->once()->with($landing)->andReturn(true);
        });

        $this->mock(ProductDataService::class, function ($mock) {
            $mock->shouldNotReceive('getByUrl');
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $short,
            'fast_mode'    => 1,
        ]);

        $response->assertStatus(200);

        $link = LinkRequest::first();
        parse_str((string) parse_url($link->affiliate_url, PHP_URL_QUERY), $params);

        $this->assertSame('https://shopee.vn/product/59917031/56759033748', $params['origin_link']);
        $this->assertStringNotContainsString('shp.ee', $params['origin_link']);
        $this->assertSame('17342330566', $params['affiliate_id']);
        $this->assertSame('tintuctonghop103', $params['sub_id']);

        $response->assertJson([
            'shopeedirect_url' => 'https://shopee.vn/product/59917031/56759033748',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // C. Fast Mode + resolver fail => cùng lỗi 422 như Normal Mode,
    //    KHÔNG tạo affiliate URL.
    // ─────────────────────────────────────────────────────────────
    public function test_fast_mode_resolver_failure_returns_same_error(): void
    {
        $short = 'https://vn.shp.ee/yvL5woPp';
        $message = 'Không lấy được sản phẩm Shopee từ link rút gọn. Vui lòng thử lại.';

        $this->mock(UrlResolverService::class, function ($mock) use ($short) {
            $mock->shouldReceive('resolve')->once()->with($short)->andReturn(null);
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $short,
            'fast_mode'    => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error' => $message]);

        $this->assertDatabaseHas('link_requests', [
            'user_id'       => $this->user->id,
            'status'        => 'failed',
            'affiliate_url' => null,
            'notes'         => $message,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // D. QUAN TRỌNG: Fast Mode và Normal Mode phải tạo affiliate URL
    //    BẰNG CÙNG MỘT QUY TẮC (không đổi tracking/affiliate_id/sub_id).
    // ─────────────────────────────────────────────────────────────
    public function test_fast_and_normal_mode_produce_identical_affiliate_url(): void
    {
        $url = 'https://shopee.vn/product/59917031/56759033748';

        $this->mock(UrlResolverService::class, function ($mock) use ($url) {
            $mock->shouldReceive('resolve')->andReturn($url);
            $mock->shouldReceive('isShopeeLanding')->andReturn(true);
        });

        $this->seedCacheHit();

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
        ])->assertStatus(200);
        $normalAffiliate = LinkRequest::latest('id')->first()->affiliate_url;

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
            'fast_mode'    => 1,
        ])->assertStatus(200);
        $fastAffiliate = LinkRequest::latest('id')->first()->affiliate_url;

        $this->assertSame(
            $normalAffiliate,
            $fastAffiliate,
            'Fast Mode và Normal Mode phải tạo affiliate URL giống hệt nhau'
        );

        $this->assertSame(56759033748, LinkRequest::latest('id')->first()->item_id);
    }

    // ─────────────────────────────────────────────────────────────
    // E. Fast Mode KHÔNG ghi vào bảng affiliate_cache.
    // ─────────────────────────────────────────────────────────────
    public function test_fast_mode_does_not_write_affiliate_cache(): void
    {
        $url = 'https://shopee.vn/product/59917031/56759033748';

        $this->mock(UrlResolverService::class, function ($mock) use ($url) {
            $mock->shouldReceive('resolve')->andReturn($url);
            $mock->shouldReceive('isShopeeLanding')->andReturn(true);
        });

        $before = AffiliateCache::count();

        $this->partialMock(AffiliateCacheService::class, function ($mock) {
            $mock->shouldNotReceive('put');
        });

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
            'fast_mode'    => 1,
        ])->assertStatus(200);

        $this->assertSame($before, AffiliateCache::count(), 'Fast Mode không được ghi affiliate_cache');
    }

    // ─────────────────────────────────────────────────────────────
    // F. Không bật fast_mode => Normal Mode giữ nguyên hành vi cũ:
    //    không có fast_mode trong response và VẪN gọi ProductData.
    // ─────────────────────────────────────────────────────────────
    public function test_fast_mode_flag_absent_keeps_normal_behaviour(): void
    {
        $url = 'https://shopee.vn/product/59917031/56759033748';

        $this->mock(UrlResolverService::class, function ($mock) use ($url) {
            $mock->shouldReceive('resolve')->andReturn($url);
            $mock->shouldReceive('isShopeeLanding')->andReturn(true);
        });

        // `once()` => Normal Mode BẮT BUỘC phải gọi ProductData.
        $this->mock(ProductDataService::class, function ($mock) {
            $mock->shouldReceive('getByUrl')->once()->andReturn($this->productDataPayload());
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('fast_mode'), 'Normal Mode không được trả fast_mode');

        $link = LinkRequest::first();
        $this->assertSame('completed', $link->status);
        $this->assertSame('api', $link->data_source);
    }

    // ─────────────────────────────────────────────────────────────
    // G. Normal Mode cache HIT vẫn trả đủ dữ liệu sản phẩm.
    // ─────────────────────────────────────────────────────────────
    public function test_normal_mode_cache_hit_still_returns_product_data(): void
    {
        $url = 'https://shopee.vn/product/59917031/56759033748';

        $this->mock(UrlResolverService::class, function ($mock) use ($url) {
            $mock->shouldReceive('resolve')->andReturn($url);
            $mock->shouldReceive('isShopeeLanding')->andReturn(true);
        });

        $this->mock(ProductDataService::class, function ($mock) {
            $mock->shouldNotReceive('getByUrl');
        });

        $this->seedCacheHit();

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => $url,
        ]);

        $response->assertStatus(200);

        $link = LinkRequest::first();
        $this->assertSame('completed', $link->status);
        $this->assertSame('Sản phẩm cached', $link->product_name);
        $this->assertSame(500, (int) $link->user_estimated_cashback);
    }

    // ─────────────────────────────────────────────────────────────
    // H. Non-Shopee + fast_mode=1 => cờ bị BỎ QUA hoàn toàn
    //    (không resolve, không trả fast_mode).
    // ─────────────────────────────────────────────────────────────
    public function test_fast_mode_flag_is_ignored_for_non_shopee_urls(): void
    {
        $this->mock(UrlResolverService::class, function ($mock) {
            $mock->shouldNotReceive('resolve');
        });

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://tiki.vn/ban-voi-tikivn-p123456',
            'fast_mode'    => 1,
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('fast_mode'));
        $this->assertNull($response->json('shopeedirect_url'));
        $this->assertSame('completed', LinkRequest::first()->status);
    }

    // ─────────────────────────────────────────────────────────────
    // I. Anonymous user bị chặn bởi middleware auth.
    // ─────────────────────────────────────────────────────────────
    public function test_anonymous_user_cannot_use_fast_mode(): void
    {
        $this->postJson('/link-requests', [
            'original_url' => 'https://shopee.vn/product/59917031/56759033748',
            'fast_mode'    => 1,
        ])->assertStatus(401);

        $this->assertSame(0, LinkRequest::count());
    }

    // ─────────────────────────────────────────────────────────────
    // J. URL không hợp lệ bị validate trước khi vào Fast Mode.
    // ─────────────────────────────────────────────────────────────
    public function test_fast_mode_rejects_invalid_url(): void
    {
        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'không-phải-url',
            'fast_mode'    => 1,
        ])->assertStatus(422);

        $this->assertSame(0, LinkRequest::count());
    }

    // ─────────────────────────────────────────────────────────────
    // K. UI: toggle Fast Mode có trong view + không polling.
    // ─────────────────────────────────────────────────────────────
    public function test_fast_mode_toggle_is_rendered_in_view(): void
    {
        $html = view('dashboard.partials.link-generator')->render();

        $this->assertStringContainsString('id="fast_mode"', $html);
        $this->assertStringContainsString('x-model="fastMode"', $html);
        $this->assertStringContainsString('Chế độ nhanh', $html);
        $this->assertStringContainsString('không tải thông tin sản phẩm', $html);
        $this->assertStringContainsString('fast_mode: this.fastMode ? 1 : 0', $html);
        $this->assertStringContainsString('Mở trang sản phẩm Shopee', $html);

        // Fast Mode phải dừng TRƯỚC startPolling() (B9).
        $fastGuard = strpos($html, 'if (data.fast_mode)');
        $pollCall = strpos($html, 'this.startPolling();');
        $this->assertNotFalse($fastGuard);
        $this->assertNotFalse($pollCall);
        $this->assertLessThan(
            $pollCall,
            $fastGuard,
            'Fast Mode phải return trước khi gọi startPolling()'
        );
    }
}
