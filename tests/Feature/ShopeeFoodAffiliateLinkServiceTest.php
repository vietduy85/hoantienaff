<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ShopeeFood\ShopeeFoodAffiliateLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeFoodAffiliateLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private const AFFILIATE_ID = '12345';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', self::AFFILIATE_ID);
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    private function service(): ShopeeFoodAffiliateLinkService
    {
        return app(ShopeeFoodAffiliateLinkService::class);
    }

    private function expectedUrl(int $restaurantId, string $subId1): string
    {
        return 'https://shopeefood.shopee.vn/now-food/shop/' . $restaurantId
            . '?shareChannel=copy_link'
            . '&utm_source=an_' . self::AFFILIATE_ID
            . '&utm_medium=affiliate_food'
            . '&utm_campaign=-'
            . '&utm_content=' . rawurlencode($subId1);
    }

    // ─── TEST 1 — short URL /u/{code} → resolve → deep link ─────────

    public function test_short_code_resolves_to_deep_link(): void
    {
        Http::fake([
            'shopeefood.vn/u/fVJCNqz' => Http::response('', 301, [
                'Location' => 'https://shopeefood.vn/now-food/shop/757850?shareChannel=copy_link',
            ]),
            'shopeefood.vn/now-food/shop/757850*' => Http::response('', 200),
        ]);

        $url = $this->service()->generateAffiliateUrl('https://shopeefood.vn/u/fVJCNqz', '1005');

        $this->assertSame($this->expectedUrl(757850, '1005'), $url);
    }

    // ─── TEST 2 — direct URL → parsed without HTTP ──────────────────

    public function test_direct_url_is_parsed_without_http(): void
    {
        $url = $this->service()->generateAffiliateUrl(
            'https://shopeefood.vn/now-food/shop/757850?shareChannel=copy_link',
            '1005',
        );

        $this->assertSame($this->expectedUrl(757850, '1005'), $url);
        Http::assertNothingSent();
    }

    // ─── TEST 3 — another restaurant ────────────────────────────────

    public function test_another_restaurant_id(): void
    {
        $url = $this->service()->generateAffiliateUrl(
            'https://shopeefood.shopee.vn/now-food/shop/1284425',
            '1005',
        );

        $this->assertSame($this->expectedUrl(1284425, '1005'), $url);
    }

    // ─── TEST 4 — different user subid1 → utm_content changes ───────

    public function test_subid1_is_used_verbatim(): void
    {
        $subid1 = $this->service()->generateAffiliateUrl(
            'https://shopeefood.vn/now-food/shop/757850',
            'testuser456',
        );

        $this->assertSame($this->expectedUrl(757850, 'testuser456'), $subid1);
    }

    // ─── TEST 5 — foreign spf.shopee.vn affiliate link ──────────────

    public function test_foreign_spf_link_is_rebuilt_with_own_affiliate_id(): void
    {
        Http::fake([
            'spf.shopee.vn/ACaZznwbfV*' => Http::response('', 302, [
                'Location' => 'https://shopeefood.vn/now-food/shop/757850?utm_source=an_17343840387&utm_medium=affiliate_food',
            ]),
            'shopeefood.vn/now-food/shop/757850*' => Http::response('', 200),
        ]);

        $url = $this->service()->generateAffiliateUrl('https://spf.shopee.vn/ACaZznwbfV', '1005');

        $this->assertSame($this->expectedUrl(757850, '1005'), $url);
        $this->assertStringNotContainsString('17343840387', $url);
    }

    // ─── TEST 6 — foreign direct affiliate URL → old params dropped ─

    public function test_foreign_direct_affiliate_url_is_rebuilt(): void
    {
        $url = $this->service()->generateAffiliateUrl(
            'https://shopeefood.vn/now-food/shop/757850?shareChannel=copy_link&utm_source=an_17343840387&utm_medium=affiliate_food&utm_campaign=-&utm_content=1005',
            'test_user',
        );

        $this->assertSame($this->expectedUrl(757850, 'test_user'), $url);
        $this->assertStringNotContainsString('17343840387', $url);
        Http::assertNothingSent();
    }

    // ─── TEST 7 — invalid short link → null, no fake URL ────────────

    public function test_invalid_short_link_returns_null(): void
    {
        Http::fake([
            'shopeefood.vn/u/badcode' => Http::response('', 404),
        ]);

        $url = $this->service()->generateAffiliateUrl('https://shopeefood.vn/u/badcode', '1005');

        $this->assertNull($url);
    }

    public function test_short_link_resolving_to_non_shopeefood_returns_null(): void
    {
        Http::fake([
            'shopeefood.vn/u/badcode' => Http::response('', 302, [
                'Location' => 'https://example.com/other',
            ]),
            'example.com/other' => Http::response('', 200),
        ]);

        $url = $this->service()->generateAffiliateUrl('https://shopeefood.vn/u/badcode', '1005');

        $this->assertNull($url);
    }

    // ─── TEST 8 — non-ShopeeFood host → null, missing setting → null ─

    public function test_non_shopeefood_host_returns_null(): void
    {
        $url = $this->service()->generateAffiliateUrl('https://shopee.vn/product/123/456', '1005');

        $this->assertNull($url);
        Http::assertNothingSent();
    }

    public function test_missing_affiliate_id_setting_returns_null(): void
    {
        Setting::set('affiliate.direct.shopee_affiliate_id', '');

        $url = $this->service()->generateAffiliateUrl('https://shopeefood.vn/now-food/shop/757850', '1005');

        $this->assertNull($url);
        Http::assertNothingSent();
    }

    // ─── resolveRestaurantId direct API ──────────────────────────────

    public function test_resolve_restaurant_id_from_direct_url(): void
    {
        $id = $this->service()->resolveRestaurantId('https://shopeefood.vn/now-food/shop/757850');

        $this->assertSame('757850', $id);
    }
}