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

        $this->assertDatabaseCount('affiliate_detect_rules', 28);
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
            'vinamilk.com.vn' => '7029539453233564544',
            'aldoshoes.vn' => '7028102478473631427',
            'wilsonsports.com.vn' => '6985534491068802784',
            'antakids.vn' => '6985531971150284313',
            'anta.com.vn' => '6979658268650552691',
            'kingfoodmart.com' => '6957824399262393441',
            'alibaba.com' => '6954336422318207062',
            'aristino.com' => '6568081282047528536',
            'cards.vpbank.com.vn' => '6516569363824775654',
            'vn.trip.com' => '6455552313033835511',
            'vib.com.vn' => '6298965523515214199',
            'vegiare.com' => '5996042828404111774',
            'minhlong.com' => '5833135818659968932',
            'promothecoffeeehouse.com.vn' => '6699917486599106569',
            'aeoneshop.com' => '6587647715581760479',
            'adidas.com.vn' => '5493145244551778830',
            'shopdunk.com' => '6800497735607325103',
            'crocs.com.vn' => '5884407006569489397',
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

        // Tiki / TikTok are recognised by hard-coded rules (unsupported), so a
        // DB rule would be dead code; FPT Shop / TGDD / Fado are not approved
        // for this token; Nature Foods Shopee PUB is a generic shopee.vn URL;
        // zalo.me / onelink.me are generic messaging/app-link hosts.
        foreach ([
            'tiki.vn',
            'tiktok.com',
            'shopee.vn',
            'fptshop.com.vn',
            'thegioididong.com',
            'fado.vn',
            'zalo.me',
            'accesstradevn.onelink.me',
        ] as $pattern) {
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
            'https://vinamilk.com.vn/san-pham/sua-tam' => '7029539453233564544',
            'https://aldoshoes.vn/giay' => '7028102478473631427',
            'https://wilsonsports.com.vn/vot' => '6985534491068802784',
            'https://antakids.vn/ao' => '6985531971150284313',
            'https://anta.com.vn/giay-chay-bo' => '6979658268650552691',
            'https://kingfoodmart.com/thit' => '6957824399262393441',
            'https://alibaba.com/search?q=laptop' => '6954336422318207062',
            'https://aristino.com/ao-so-mi' => '6568081282047528536',
            'https://cards.vpbank.com.vn/the-tin-dung' => '6516569363824775654',
            'https://vn.trip.com/hotels' => '6455552313033835511',
            'https://vib.com.vn/the-tin-dung' => '6298965523515214199',
            'https://vegiare.com/ve-may-bay' => '5996042828404111774',
            'https://minhlong.com/pages/eshop' => '5833135818659968932',
            'https://promothecoffeeehouse.com.vn/promo' => '6699917486599106569',
            'https://aeoneshop.com/san-pham' => '6587647715581760479',
            'https://www.adidas.com.vn/vi' => '5493145244551778830',
            'https://adidas.com.vn/vi' => '5493145244551778830',
            'https://adidasvn.pxf.io/c/5371526/2850846/33066' => '5493145244551778830',
            'https://shopdunk.com/iphone' => '6800497735607325103',
            'https://www.shopdunk.com/iphone' => '6800497735607325103',
            'https://crocs.com.vn/collections/all' => '5884407006569489397',
            'https://www.crocs.com.vn/collections/all' => '5884407006569489397',
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

    public function test_tiktok_url_still_resolves_to_tiktok_shop(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);
        app(AffiliateDetectRuleRepository::class)->flush();

        $target = app(PlatformResolver::class)->resolve('https://www.tiktok.com/@shop/video/123');

        $this->assertSame('TikTok Shop', $target->platform);
        $this->assertFalse($target->isAccessTrade());
    }

    public function test_seeded_rules_are_hostname_narrow_and_do_not_overreach(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);
        app(AffiliateDetectRuleRepository::class)->flush();

        // The VPBank card campaign is pinned to the `cards.` subdomain only,
        // so neither the parent domain nor sibling subdomains may be hijacked.
        foreach (['https://vpbank.com.vn/the-tin-dung', 'https://vayonline.vpbank.com.vn/vay'] as $url) {
            $target = app(PlatformResolver::class)->resolve($url);
            $this->assertFalse($target->isAccessTrade(), $url);
        }

        // The AEON campaign URL is an app-link host (get.aeoneshop.com/a/...);
        // its registrable domain must still be recognised.
        $aeon = app(PlatformResolver::class)->resolve('https://get.aeoneshop.com/a/key_live_abc');
        $this->assertTrue($aeon->isAccessTrade());
        $this->assertSame('6587647715581760479', (string) $aeon->campaignId);

        // Trip.com is pinned to the approved VN host, not the global apex.
        $trip = app(PlatformResolver::class)->resolve('https://trip.com/hotels');
        $this->assertFalse($trip->isAccessTrade());

        // The generic pxf.io tracking domain must never be a rule (only the
        // narrow Adidas campaign host is), and Crocs/ShopDunk rules must not
        // overreach to the global domain or a different TLD.
        foreach (['https://pxf.io/abc', 'https://crocs.com/', 'https://shopdunk.vn/'] as $url) {
            $target = app(PlatformResolver::class)->resolve($url);
            $this->assertFalse($target->isAccessTrade(), $url);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AffiliateDetectRuleSeeder::class);
        $this->seed(AffiliateDetectRuleSeeder::class);

        $this->assertDatabaseCount('affiliate_detect_rules', 28);
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

        $this->assertDatabaseCount('affiliate_detect_rules', 28);
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
