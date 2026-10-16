<?php

namespace Tests\Feature\AccessTrade;

use App\Models\AffiliateDetectRule;
use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccessTrade\AccessTradeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CellphoneS is served through AccessTrade. The affiliate API returns only a
 * tracking link, so product name/image/price are scraped (best-effort) from the
 * merchant page. A metadata failure must never break the created link, and no
 * cashback may be shown because AccessTrade returns no per-product commission.
 */
class AccessTradeCellphoneMetadataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.product_metadata.verify_dns' => false]);

        $this->user = User::factory()->create(['username' => 'at_cell']);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $config = app(AccessTradeConfig::class);
        $config->setEnabled(true);
        $config->setToken('super-secret-token');

        AffiliateDetectRule::create([
            'name' => 'CellphoneS',
            'pattern' => 'cellphones.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6259155740535091857',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ]);
    }

    public function test_cellphones_link_is_enriched_with_product_metadata(): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response($this->accesstradePayload(), 200),
            'cellphones.com.vn/*' => Http::response(
                '<!doctype html><html><head>'
                .'<meta property="og:title" content="Laptop Lenovo IdeaPad Slim 3 14IWC11" />'
                .'<meta property="og:image" content="https://cdn.cellphones.com.vn/media/lenovo.jpg" />'
                .'<meta property="product:price:amount" content="12900000" />'
                .'</head><body></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://cellphones.com.vn/laptop-lenovo-ideapad-slim-3.html',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'platform' => 'AccessTrade',
            'affiliate_url' => 'https://track.accesstrade.vn/aff?x=1',
            'status' => 'completed',
        ]);

        $link = LinkRequest::latest()->first();
        $this->assertSame('completed', $link->status);
        $this->assertSame('https://track.accesstrade.vn/aff?x=1', $link->affiliate_url);
        $this->assertSame('accesstrade-api', $link->data_source);
        $this->assertSame('Laptop Lenovo IdeaPad Slim 3 14IWC11', $link->product_name);
        $this->assertSame('https://cdn.cellphones.com.vn/media/lenovo.jpg', $link->product_image);
        $this->assertSame(12900000, (int) $link->product_price);
    }

    public function test_no_cashback_is_shown_without_commission_data(): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response($this->accesstradePayload(), 200),
            'cellphones.com.vn/*' => Http::response(
                '<!doctype html><html><head>'
                .'<meta property="og:title" content="Laptop Lenovo" />'
                .'<meta property="product:price:amount" content="12900000" />'
                .'</head><body></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://cellphones.com.vn/laptop-lenovo.html',
        ])->assertOk();

        $link = LinkRequest::latest()->first();

        // Price is known (product price), but with no commission we must NOT
        // fabricate a cashback estimate.
        $this->assertNull($link->estimated_cashback);
        $this->assertNull($link->user_estimated_cashback);

        $this->actingAs($this->user)
            ->getJson('/api/link-request/'.$link->id)
            ->assertOk()
            ->assertJson([
                'status' => 'completed',
                'user_estimated_cashback' => null,
                'estimated_cashback' => null,
            ]);
    }

    public function test_metadata_failure_does_not_break_completed_link(): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response($this->accesstradePayload(), 200),
            'cellphones.com.vn/*' => Http::response('', 500),
        ]);

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://cellphones.com.vn/laptop-lenovo.html',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'affiliate_url' => 'https://track.accesstrade.vn/aff?x=1',
            'status' => 'completed',
        ]);

        $link = LinkRequest::latest()->first();
        $this->assertSame('completed', $link->status);
        $this->assertSame('https://track.accesstrade.vn/aff?x=1', $link->affiliate_url);
        $this->assertNull($link->product_name);
        $this->assertNull($link->product_image);
    }

    public function test_metadata_fetch_is_never_sent_for_shopee(): void
    {
        Http::fake();

        // A broad AccessTrade rule that would hijack Shopee if the overlay ran
        // first — the Shopee direct flow must win and never hit metadata hosts.
        AffiliateDetectRule::create([
            'name' => 'Hijack shopee',
            'pattern' => 'shopee',
            'match_type' => AffiliateDetectRule::MATCH_SUBSTRING,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '999',
            'priority' => 1,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ]);

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://shopee.vn/product/1/2',
        ])->assertOk()->assertJson(['platform' => 'Shopee']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'accesstrade'));
    }

    /**
     * @return array<string, mixed>
     */
    private function accesstradePayload(): array
    {
        return [
            'success' => true,
            'data' => [
                'error_link' => [],
                'success_link' => [[
                    'aff_link' => 'https://track.accesstrade.vn/aff?x=1',
                    'short_link' => 'https://at.vn/abc',
                ]],
                'suspend_url' => [],
            ],
        ];
    }
}
