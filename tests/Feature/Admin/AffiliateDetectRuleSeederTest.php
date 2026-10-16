<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateDetectRule;
use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccessTrade\AccessTradeConfig;
use App\Services\Url\AffiliateDetectRuleRepository;
use App\Services\Url\PlatformResolver;
use App\Services\Url\ResolvedTarget;
use Database\Seeders\AffiliateDetectRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies the idempotent CellphoneS detect rule seeded by
 * {@see AffiliateDetectRuleSeeder}, that it routes cellphones.com.vn to the
 * AccessTrade strategy, and that the direct-link flow creates an AccessTrade
 * link for that domain (HTTP is faked — no live call).
 */
class AffiliateDetectRuleSeederTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['username' => 'cellphones_user']);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    public function test_seeder_creates_cellphones_access_trade_rule(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);

        $this->assertDatabaseHas('affiliate_detect_rules', [
            'name' => 'CellphoneS',
            'pattern' => 'cellphones.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6259155740535091857',
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ]);

        $this->assertDatabaseCount('affiliate_detect_rules', 10);
    }

    public function test_seeder_creates_every_approved_access_trade_merchant(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);

        $expected = [
            'cellphones.com.vn' => '6259155740535091857',
            'dienthoaivui.com.vn' => '6685985692750012237',
            'ben.com.vn' => '6544141033462679814',
            'shondoshoes.com' => '6775153232642032998',
            'hoanghamobile.com' => '5229340396064683522',
            'adidasvn.pxf.io' => '5493145244551778830',
            'concung.com' => '5204532880919025215',
            'lug.vn' => '5199030632523966380',
            'mediamart.vn' => '6009072433920808367',
            'bitis.com.vn' => '5320689651876146371',
        ];

        foreach ($expected as $pattern => $campaignId) {
            $this->assertDatabaseHas('affiliate_detect_rules', [
                'pattern' => $pattern,
                'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
                'platform' => 'AccessTrade',
                'strategy' => 'accesstrade_api',
                'campaign_id' => $campaignId,
                'status' => AffiliateDetectRule::STATUS_ACTIVE,
            ]);
        }
    }

    public function test_seeder_skips_unapproved_and_code_shadowed_campaigns(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);

        // Tiki is recognised by a hard-coded rule (unsupported), so a DB rule
        // would be dead code; FPT Shop / TGDD / Fado are not approved for this
        // token; Nature Foods Shopee PUB is a generic shopee.vn URL.
        foreach (['tiki.vn', 'shopee.vn', 'fptshop.com.vn', 'thegioididong.com', 'fado.vn'] as $pattern) {
            $this->assertDatabaseMissing('affiliate_detect_rules', ['pattern' => $pattern]);
        }
    }

    public function test_seeded_rules_route_each_domain_to_its_pinned_campaign(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);
        app(AffiliateDetectRuleRepository::class)->flush();

        $cases = [
            'https://dienthoaivui.com.vn/may-cu' => '6685985692750012237',
            'https://ben.com.vn/tin-tuc/flashsale/' => '6544141033462679814',
            'https://shondoshoes.com/san-pham/giay-dep' => '6775153232642032998',
            'https://hoanghamobile.com/dien-thoai' => '5229340396064683522',
            'https://concung.com/sua-bot' => '5204532880919025215',
            'https://lug.vn/tui-xach' => '5199030632523966380',
            'https://mediamart.vn/tivi' => '6009072433920808367',
            'https://bitis.com.vn/san-pham/giay-the-thao' => '5320689651876146371',
        ];

        foreach ($cases as $url => $campaignId) {
            $target = app(PlatformResolver::class)->resolve($url);

            $this->assertTrue($target->isAccessTrade(), $url);
            $this->assertSame('AccessTrade', $target->platform, $url);
            $this->assertSame(ResolvedTarget::STRATEGY_ACCESSTRADE, $target->strategy, $url);
            $this->assertSame($campaignId, (string) $target->campaignId, $url);
        }
    }

    public function test_nature_foods_shopee_url_still_resolves_to_shopee(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);
        app(AffiliateDetectRuleRepository::class)->flush();

        $target = app(PlatformResolver::class)->resolve('https://shopee.vn/naturefoods_vn');

        $this->assertSame('Shopee', $target->platform);
        $this->assertFalse($target->isAccessTrade());
    }

    public function test_tiki_url_stays_unsupported_and_is_not_hijacked(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);
        app(AffiliateDetectRuleRepository::class)->flush();

        $target = app(PlatformResolver::class)->resolve('https://tiki.vn/san-pham/p123');

        $this->assertSame('Tiki', $target->platform);
        $this->assertSame(ResolvedTarget::STRATEGY_UNSUPPORTED, $target->strategy);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);
        $this->seed(AffiliateDetectRuleSeeder::class);

        $this->assertDatabaseCount('affiliate_detect_rules', 10);
    }

    public function test_seeder_does_not_overwrite_an_existing_rule(): void
    {
        AffiliateDetectRule::create([
            'name' => 'Edited by admin',
            'pattern' => 'cellphones.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '999',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ]);

        $this->seed(AffiliateDetectRuleSeeder::class);

        $this->assertDatabaseCount('affiliate_detect_rules', 10);
        $rule = AffiliateDetectRule::where('pattern', 'cellphones.com.vn')->firstOrFail();
        $this->assertSame('Edited by admin', $rule->name);
        $this->assertSame('999', (string) $rule->campaign_id);
    }

    public function test_resolver_maps_cellphones_to_access_trade_with_pinned_campaign(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);
        app(AffiliateDetectRuleRepository::class)->flush();

        $target = app(PlatformResolver::class)
            ->resolve('https://cellphones.com.vn/laptop-lenovo-ideapad-slim-3-14iwc11-83rq002pvn.html');

        $this->assertTrue($target->isAccessTrade());
        $this->assertSame('AccessTrade', $target->platform);
        $this->assertSame(ResolvedTarget::STRATEGY_ACCESSTRADE, $target->strategy);
        $this->assertSame('6259155740535091857', (string) $target->campaignId);
    }

    public function test_cellphones_url_creates_access_trade_link_via_direct_flow(): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response([
                'success' => true,
                'data' => [
                    'error_link' => [],
                    'success_link' => [[
                        'aff_link' => 'https://go.isclix.com/deep_link/abc',
                        'short_link' => 'https://at.vn/abc',
                    ]],
                ],
            ], 200),
        ]);

        $this->seed(AffiliateDetectRuleSeeder::class);
        app(AffiliateDetectRuleRepository::class)->flush();

        $config = app(AccessTradeConfig::class);
        $config->setEnabled(true);
        $config->setToken('seeded-secret-token');

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://cellphones.com.vn/laptop-lenovo-ideapad-slim-3-14iwc11-83rq002pvn.html',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'platform' => 'AccessTrade',
            'affiliate_url' => 'https://go.isclix.com/deep_link/abc',
            'status' => 'completed',
        ]);

        $link = LinkRequest::latest()->first();
        $this->assertSame('completed', $link->status);
        $this->assertSame('accesstrade-api', $link->data_source);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/product_link/create')
                && ($request['campaign_id'] ?? null) === '6259155740535091857';
        });
    }
}
