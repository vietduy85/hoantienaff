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
 * AccessTrade is used for link creation ONLY when:
 *   - the URL matches an admin detect rule mapped to AccessTrade, AND
 *   - AccessTrade is enabled with a configured token.
 *
 * A link is never marked `completed` with an empty affiliate_url, and existing
 * Shopee/TikTok/Lazada flows are never intercepted.
 */
class AccessTradeDirectLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['username' => 'at_user']);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    private function enableAccessTrade(string $token = 'super-secret-token'): void
    {
        $config = app(AccessTradeConfig::class);
        $config->setEnabled(true);
        $config->setToken($token);
    }

    private function ruleForAccessTrade(string $host = 'thefaceshopvn.myharavan.com', ?string $campaignId = '123'): void
    {
        AffiliateDetectRule::create([
            'name' => 'TheFaceShop',
            'pattern' => $host,
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => $campaignId,
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ]);
    }

    public function test_configured_accesstrade_creates_link_for_matched_domain(): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response([
                'success' => true,
                'data' => [
                    'error_link' => [],
                    'success_link' => [[
                        'aff_link' => 'https://track.accesstrade.vn/aff?x=1',
                        'short_link' => 'https://at.vn/abc',
                    ]],
                    'suspend_url' => [],
                ],
            ], 200),
        ]);

        $this->enableAccessTrade();
        $this->ruleForAccessTrade();

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://thefaceshopvn.myharavan.com/products/abc-i.1.2',
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

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/product_link/create')
                && ($request->header('Authorization')[0] ?? null) === 'Token super-secret-token';
        });
    }

    public function test_accesstrade_error_link_marks_link_failed_never_completed(): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response([
                'success' => false,
                'data' => [
                    'error_link' => [['url' => 'https://thefaceshopvn.myharavan.com/x', 'message' => 'invalid']],
                    'success_link' => [],
                ],
            ], 200),
        ]);

        $this->enableAccessTrade();
        $this->ruleForAccessTrade();

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://thefaceshopvn.myharavan.com/products/abc-i.1.2',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    public function test_accesstrade_success_but_empty_link_marks_failed(): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response([
                'success' => true,
                'data' => ['error_link' => [], 'success_link' => [['aff_link' => '', 'short_link' => '']]],
            ], 200),
        ]);

        $this->enableAccessTrade();
        $this->ruleForAccessTrade();

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://thefaceshopvn.myharavan.com/products/abc-i.1.2',
        ])->assertStatus(422);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    public function test_matched_domain_without_campaign_is_failed_not_completed(): void
    {
        Http::fake([
            'api.accesstrade.vn/*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);

        $this->enableAccessTrade();
        // Rule with no pinned campaign; campaigns() returns none -> no match.
        $this->ruleForAccessTrade('thefaceshopvn.myharavan.com', null);

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://thefaceshopvn.myharavan.com/products/abc-i.1.2',
        ])->assertStatus(422);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    public function test_disabled_accesstrade_falls_back_to_unsupported(): void
    {
        Http::fake();

        app(AccessTradeConfig::class)->setEnabled(false);
        app(AccessTradeConfig::class)->setToken('super-secret-token');
        $this->ruleForAccessTrade();

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://thefaceshopvn.myharavan.com/products/abc-i.1.2',
        ])->assertStatus(422);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);

        Http::assertNothingSent();
    }

    public function test_enabled_but_missing_token_falls_back_to_unsupported(): void
    {
        Http::fake();

        app(AccessTradeConfig::class)->setEnabled(true);
        app(AccessTradeConfig::class)->setToken(null);
        $this->ruleForAccessTrade();

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://thefaceshopvn.myharavan.com/products/abc-i.1.2',
        ])->assertStatus(422);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);

        Http::assertNothingSent();
    }

    public function test_shopee_url_is_never_intercepted_by_accesstrade(): void
    {
        Http::fake();

        $this->enableAccessTrade();
        // A broad rule that would match shopee if the overlay ran first.
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

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://shopee.vn/product/1/2',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'platform' => 'Shopee']);

        $link = LinkRequest::latest()->first();
        $this->assertSame('Shopee', $link->platform);
        $this->assertStringContainsString('s.shopee.vn/an_redir', (string) $link->affiliate_url);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'accesstrade'));
    }
}
